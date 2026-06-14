import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

const CARD_CLASS = 'LinkPreview-card';
const WRAPPER_CLASS = 'LinkPreview-card-wrapper';
const HOVER_CLASS = 'LinkPreview-hovercard';
const TOGGLE_CLASS = 'LinkPreview-previewToggle';
const SIG_ATTR = 'data-lp-sig';
const COUNT_ATTR = 'data-lp-n';
const BOUND_ATTR = 'data-lp-bound';
const DESC_MAX = 220;
const HOVER_SHOW_MS = 250;
const HOVER_HIDE_MS = 300;

// Hover previews need a real pointer. On touch devices links must navigate
// on first tap, so hidden previews get an explicit tap target instead
// (see buildPreviewToggle).
const POINTER_HOVERS =
    typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

// anchor element → { preview, body } for hover lookups. WeakMaps so entries die
// with their DOM nodes.
const anchorRegistry = new WeakMap();
// .Post-body element → its current previews list, so toggle handlers can
// re-render the post without going through Mithril.
const bodyRegistry = new WeakMap();
// Anchors that already carry hover listeners. Separate from BOUND_ATTR:
// the attribute tracks "currently preview-bearing" (and is recounted by the
// staleness probe), while listeners can only be attached once per element.
const hoverListenersAttached = new WeakSet();

extend(CommentPost.prototype, 'oncreate', function () {
    processPreviews(this);
});

extend(CommentPost.prototype, 'onupdate', function () {
    processPreviews(this);
});

function processPreviews(commentPost) {
    const post = commentPost.attrs && commentPost.attrs.post;
    if (!post || !post.attribute) return;

    const previews = post.attribute('linkPreviews');
    const list = Array.isArray(previews) ? previews : [];

    const root = commentPost.element;
    if (!root) return;

    const body = root.querySelector('.Post-body');
    if (!body) return;

    // Skip the rebuild when nothing changed. Two staleness signals:
    //   - sig: preview list + per-preview display state (dismiss/pin from
    //     another actor while the page is open changes it);
    //   - bound-anchor count: a post edit makes Mithril reset the body's
    //     innerHTML, which keeps the element (and its sig attribute!) but
    //     wipes our injected cards and anchor bindings. Fresh anchors carry
    //     no BOUND_ATTR, so the count drops and we re-render.
    const sig = computeSig(list);
    const bound = body.querySelectorAll('a[' + BOUND_ATTR + ']').length;
    if (body.getAttribute(SIG_ATTR) === sig && bound === Number(body.getAttribute(COUNT_ATTR) || 0)) return;

    renderBody(body, list);
    body.setAttribute(SIG_ATTR, sig);
}

function computeSig(list) {
    return list
        .map((e) => `${e.url}|${e.dismissed ? 'd' : ''}${e.pinned ? 'p' : ''}${e.canToggle ? 'm' : ''}`)
        .join('\n');
}

/**
 * (Re)build all cards and hover bindings for one post body.
 *
 * Visibility rule per URL:
 *   - pinned          → inline card (author/mod forced it)
 *   - dismissed       → no card, hover preview only
 *   - otherwise       → inline card only if the post contains the URL as a
 *                       RAW link (text == href). Titled links ([Title](url),
 *                       [url=...]Title[/url]) are hover-only by default.
 * The card goes after the first raw occurrence of the URL, or the first
 * occurrence if there is none.
 */
function renderBody(body, list) {
    bodyRegistry.set(body, list);

    body.querySelectorAll('.' + WRAPPER_CLASS + ', .' + TOGGLE_CLASS).forEach((el) => el.remove());

    const byUrl = new Map();
    for (const preview of list) {
        if (preview && preview.url) byUrl.set(preview.url, preview);
    }

    const anchorsByUrl = new Map();
    let bound = 0;

    for (const anchor of body.querySelectorAll('a[href]')) {
        if (anchor.closest('.' + WRAPPER_CLASS)) continue; // our own card link
        const preview = byUrl.get(anchor.getAttribute('href'));
        if (!preview) {
            // Lost its preview since the last render (e.g. fetch finished with
            // no usable metadata). Unmark it so the staleness probe's count
            // stays in sync and the hover handler goes inert.
            if (anchor.hasAttribute(BOUND_ATTR)) {
                anchor.removeAttribute(BOUND_ATTR);
                anchorRegistry.delete(anchor);
            }
            continue;
        }

        anchorRegistry.set(anchor, { preview, body });
        anchor.setAttribute(BOUND_ATTR, '1');
        if (POINTER_HOVERS && !hoverListenersAttached.has(anchor)) {
            hoverListenersAttached.add(anchor);
            bindHover(anchor);
        }
        bound++;

        const arr = anchorsByUrl.get(preview.url) || [];
        arr.push(anchor);
        anchorsByUrl.set(preview.url, arr);
    }

    body.setAttribute(COUNT_ATTR, String(bound));

    for (const [url, anchors] of anchorsByUrl) {
        const preview = byUrl.get(url);
        const rawAnchor = anchors.find(isRawLink);

        const visible = preview.pinned || (!preview.dismissed && !!rawAnchor);
        const at = rawAnchor || anchors[0];

        if (visible) {
            at.parentNode.insertBefore(buildWrapper(preview, body), at.nextSibling);
        } else if (!POINTER_HOVERS) {
            // Touch devices have no hover, and hijacking the link's first tap
            // is the classic iOS double-tap anti-pattern (links must navigate
            // on first tap). Instead: a small explicit tap target after the
            // link opens the preview overlay; tapping anywhere else closes it.
            at.parentNode.insertBefore(buildPreviewToggle(preview, body, at), at.nextSibling);
        }
    }
}

function buildPreviewToggle(preview, body, anchor) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = TOGGLE_CLASS;
    const label = trans('preview_aria_prefix', 'Link preview');
    btn.setAttribute('aria-label', `${label}: ${preview.url}`);
    btn.title = label;
    const icon = document.createElement('i');
    icon.className = 'fas fa-eye';
    icon.setAttribute('aria-hidden', 'true');
    btn.appendChild(icon);
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (hoverEl && hoverAnchor === anchor) {
            hideHover(); // second tap on the trigger closes the preview
        } else {
            showHover(anchor, preview, body);
        }
    });
    return btn;
}

/**
 * A link is "raw" when its visible text is the URL itself (what you get by
 * pasting a bare URL). Tolerates a missing scheme or trailing slash in the
 * displayed text. Anything else — Markdown/BBCode titles, image links — is
 * "titled" and defaults to hover-only.
 */
function isRawLink(anchor) {
    const href = (anchor.getAttribute('href') || '').trim();
    const text = (anchor.textContent || '').trim();
    if (!href || !text) return false;
    if (text === href) return true;
    const norm = (s) => s.replace(/^https?:\/\//i, '').replace(/\/$/, '');
    return norm(text) === norm(href);
}

function findAnchor(body, url) {
    for (const anchor of body.querySelectorAll('a[href]')) {
        if (anchor.getAttribute('href') === url && !anchor.closest('.' + WRAPPER_CLASS)) return anchor;
    }
    return null;
}

function findWrapper(body, url) {
    for (const w of body.querySelectorAll('.' + WRAPPER_CLASS)) {
        if (w.dataset.lpUrl === url) return w;
    }
    return null;
}

// ─── Inline card ────────────────────────────────────────────────────────

function buildWrapper(preview, body) {
    // The card itself is the click target; the hide button is layered on
    // top, with stopPropagation so clicking ✕ doesn't open the link.
    const wrapper = document.createElement('div');
    wrapper.className = WRAPPER_CLASS;
    wrapper.dataset.lpUrl = preview.url;

    wrapper.appendChild(buildCard(preview));

    if (preview.canToggle) {
        wrapper.appendChild(buildHideButton(preview, body));
    }

    return wrapper;
}

function buildCard(preview) {
    const card = document.createElement('a');
    card.className = CARD_CLASS;
    card.href = preview.finalUrl || preview.url;
    card.target = '_blank';
    card.rel = 'nofollow noopener noreferrer';

    // Screen readers: collapse the multi-div content into a single descriptive
    // accessible name. Without this they announce site / title / desc as three
    // separate text nodes (verbose); with aria-label they read it as one link.
    const labelParts = [
        preview.siteName || preview.domain,
        preview.title,
        preview.description ? truncate(preview.description, DESC_MAX) : null,
    ].filter(Boolean);
    const previewLabel = trans('preview_aria_prefix', 'Link preview');
    card.setAttribute('aria-label', `${previewLabel}: ${labelParts.join(' — ')}`);

    // A brand/logo image (the forum share image on self-links, flagged
    // `imageFit:'contain'`) is rendered as a small favicon next to the site
    // name rather than a full-size thumbnail — self-links carry no per-discussion
    // content image, so the big slot would just be a blown-up logo.
    const isBrandLogo = !!preview.image && preview.imageFit === 'contain';

    if (preview.image && !isBrandLogo) {
        const img = document.createElement('img');
        img.className = CARD_CLASS + '-image';
        img.src = preview.image;
        img.alt = ''; // decorative — text content provides the info
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener(
            'error',
            () => {
                const ph = document.createElement('div');
                ph.className = CARD_CLASS + '-image ' + CARD_CLASS + '-image--missing';
                ph.setAttribute('aria-hidden', 'true'); // empty placeholder; screen readers ignore
                img.replaceWith(ph);
            },
            { once: true }
        );
        card.appendChild(img);
    }

    const text = document.createElement('div');
    text.className = CARD_CLASS + '-text';

    const site = document.createElement('div');
    site.className = CARD_CLASS + '-site';
    if (isBrandLogo) {
        const fav = document.createElement('img');
        fav.className = CARD_CLASS + '-site-favicon';
        fav.src = preview.image;
        fav.alt = ''; // decorative — site name text follows
        fav.loading = 'lazy';
        fav.referrerPolicy = 'no-referrer';
        fav.addEventListener('error', () => fav.remove(), { once: true }); // drop silently on 404
        site.appendChild(fav);
    }
    const siteLabel = document.createElement('span');
    siteLabel.textContent = preview.siteName || preview.domain || '';
    site.appendChild(siteLabel);
    text.appendChild(site);

    const title = document.createElement('div');
    title.className = CARD_CLASS + '-title';
    title.textContent = preview.title;
    text.appendChild(title);

    if (preview.description) {
        const desc = document.createElement('div');
        desc.className = CARD_CLASS + '-desc';
        desc.textContent = truncate(preview.description, DESC_MAX);
        text.appendChild(desc);
    }

    card.appendChild(text);
    return card;
}

function buildHideButton(preview, body) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = CARD_CLASS + '-dismiss';
    // The visible glyph is `×` but screen readers should hear "Hide preview"
    // — pair an aria-hidden glyph with an accessible label.
    const label = trans('hide_preview', 'Hide preview');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    const glyph = document.createElement('span');
    glyph.setAttribute('aria-hidden', 'true');
    glyph.textContent = '×';
    btn.appendChild(glyph);
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        btn.disabled = true;
        sendToggle(preview, 'dismiss')
            .then(() => {
                preview.dismissed = true;
                preview.pinned = false;
                refreshBody(body);
                // Keep keyboard/screen-reader users anchored: focus the link
                // whose card just collapsed.
                const anchor = findAnchor(body, preview.url);
                if (anchor) anchor.focus();
            })
            .catch(() => {
                btn.disabled = false;
            });
    });
    return btn;
}

// Re-render a post body after a state change and refresh the sig so the
// next Mithril onupdate doesn't redo the work.
function refreshBody(body) {
    const list = bodyRegistry.get(body) || [];
    renderBody(body, list);
    body.setAttribute(SIG_ATTR, computeSig(list));
}

// ─── Hover preview overlay ──────────────────────────────────────────────
//
// One singleton, appended to document.body (so no post-body overflow or
// stacking context can clip it), positioned in page coordinates next to the
// hovered link. Shown for any preview-bearing link that doesn't currently have
// its inline card right after it; carries the "Show preview in post" action
// for actors with edit permission.

let hoverEl = null;
let hoverAnchor = null;
let showTimer = 0;
let hideTimer = 0;
let globalHoverHandlersBound = false;

function bindHover(anchor) {
    anchor.addEventListener('mouseenter', () => {
        const reg = anchorRegistry.get(anchor);
        if (!reg) return;
        // Inline card directly after the link → nothing to preview.
        const next = anchor.nextElementSibling;
        if (next && next.classList.contains(WRAPPER_CLASS)) return;

        window.clearTimeout(hideTimer);
        if (hoverAnchor === anchor && hoverEl) return; // already showing this one
        window.clearTimeout(showTimer);
        showTimer = window.setTimeout(() => showHover(anchor, reg.preview, reg.body), HOVER_SHOW_MS);
    });
    anchor.addEventListener('mouseleave', () => {
        window.clearTimeout(showTimer);
        scheduleHide();
    });
}

function scheduleHide() {
    window.clearTimeout(hideTimer);
    hideTimer = window.setTimeout(hideHover, HOVER_HIDE_MS);
}

function hideHover() {
    window.clearTimeout(showTimer);
    window.clearTimeout(hideTimer);
    if (hoverEl) {
        hoverEl.remove();
        hoverEl = null;
        hoverAnchor = null;
    }
}

function showHover(anchor, preview, body) {
    hideHover();
    if (!document.contains(anchor)) return;

    bindGlobalHoverHandlers();

    const el = document.createElement('div');
    el.className = HOVER_CLASS;
    el.appendChild(buildCard(preview));

    // The pin action lives here (not as a permanent placeholder in the post
    // body): visible only to author/mods, and only while no inline card for
    // this URL exists in the post.
    if (preview.canToggle && !findWrapper(body, preview.url)) {
        const actions = document.createElement('div');
        actions.className = HOVER_CLASS + '-actions';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = HOVER_CLASS + '-pin';
        const label = trans('pin_preview', 'Pin preview');
        btn.textContent = label;
        btn.setAttribute('aria-label', `${label}: ${preview.url}`);
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            btn.disabled = true;
            sendToggle(preview, 'pin')
                .then(() => {
                    preview.pinned = true;
                    preview.dismissed = false;
                    hideHover();
                    refreshBody(body);
                    // Move focus to the freshly pinned card.
                    const wrapper = findWrapper(body, preview.url);
                    const cardLink = wrapper && wrapper.querySelector('.' + CARD_CLASS);
                    if (cardLink) cardLink.focus();
                })
                .catch(() => {
                    btn.disabled = false;
                });
        });

        actions.appendChild(btn);
        el.appendChild(actions);
    }

    el.addEventListener('mouseenter', () => window.clearTimeout(hideTimer));
    el.addEventListener('mouseleave', scheduleHide);

    document.body.appendChild(el);
    positionHover(el, anchor);

    hoverEl = el;
    hoverAnchor = anchor;
}

function positionHover(el, anchor) {
    const margin = 8; // viewport edge clearance
    const gap = 6; // distance from the link
    const rect = anchor.getBoundingClientRect();
    const w = el.offsetWidth;
    const h = el.offsetHeight;

    let left = rect.left + window.scrollX;
    const maxLeft = window.scrollX + document.documentElement.clientWidth - w - margin;
    if (left > maxLeft) left = Math.max(window.scrollX + margin, maxLeft);

    // Below the link by default; above when it wouldn't fit the viewport but
    // there's room on top.
    let top = rect.bottom + window.scrollY + gap;
    if (rect.bottom + gap + h > window.innerHeight && rect.top - gap - h > 0) {
        top = rect.top + window.scrollY - gap - h;
    }

    el.style.left = `${left}px`;
    el.style.top = `${top}px`;
}

function bindGlobalHoverHandlers() {
    if (globalHoverHandlersBound) return;
    globalHoverHandlersBound = true;
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') hideHover();
    });
    // Capture-phase: also fires for clicks that trigger SPA navigation, so
    // the overlay never survives onto another page. Preview-toggle buttons
    // are exempt — their own handler decides between toggle-off and
    // switching the overlay to another link.
    document.addEventListener(
        'click',
        (e) => {
            const onToggle = e.target.closest && e.target.closest('.' + TOGGLE_CLASS);
            if (hoverEl && !hoverEl.contains(e.target) && !onToggle) hideHover();
        },
        true
    );
}

// ─── Shared helpers ─────────────────────────────────────────────────────

function sendToggle(preview, action) {
    return app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/link-previews/posts/${preview.postId}/previews/${preview.previewId}/${action}`,
    });
}

function trans(key, fallback) {
    const out = app.translator.trans(`ekumanov-link-preview.forum.${key}`);
    const s = typeof out === 'string' ? out : Array.isArray(out) ? out.join('') : '';
    return s || fallback;
}

function truncate(s, max) {
    if (!s) return '';
    const trimmed = s.trim();
    if (trimmed.length <= max) return trimmed;
    return trimmed.slice(0, max - 1).replace(/\s+\S*$/, '') + '…';
}
