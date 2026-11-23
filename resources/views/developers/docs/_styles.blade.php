<style>
    /*
     * Rendered Markdown only, scoped to .docs-prose so nothing else is affected. Colours come from the app's own
     * theme variables (resources/css/app.css) so the documentation follows the rest of the dashboard.
     */
    .docs-prose { font-size: 0.9375rem; line-height: 1.7; color: #374151; overflow-wrap: anywhere; }
    .docs-prose > :first-child { margin-top: 0; }
    .docs-prose h2 { font-size: 1.3rem; font-weight: 600; margin: 2.25rem 0 0.75rem; padding-top: 1.25rem; border-top: 1px solid #e5e7eb; color: #111827; scroll-margin-top: 5rem; }
    .docs-prose h2:first-child { border-top: 0; margin-top: 0; padding-top: 0; }
    .docs-prose h3 { font-size: 1.05rem; font-weight: 600; margin: 1.75rem 0 0.5rem; color: #111827; scroll-margin-top: 5rem; }
    .docs-prose p { margin: 0.75rem 0; }
    .docs-prose ul, .docs-prose ol { margin: 0.75rem 0; padding-left: 1.4rem; }
    .docs-prose ul { list-style: disc; }
    .docs-prose ol { list-style: decimal; }
    .docs-prose li { margin: 0.3rem 0; }
    .docs-prose a { color: var(--color-brand-violet); text-decoration: underline; text-underline-offset: 2px; }
    .docs-prose a:hover { color: var(--color-brand-violet-deep); }
    .docs-prose a:focus-visible, .docs-copy:focus-visible { outline: 2px solid var(--color-brand-violet); outline-offset: 2px; border-radius: 0.25rem; }
    .docs-prose code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.84em; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 0.375rem; padding: 0.08rem 0.35rem; color: #111827; }

    /* Code blocks: dark panel, horizontal scroll on narrow screens, Copy button added by the page script. */
    .docs-pre { position: relative; margin: 1rem 0; }
    .docs-prose pre { background: var(--color-brand-dark, #1c1e54); color: #f3f4f6; border-radius: 0.75rem; padding: 1rem 1.1rem; overflow-x: auto; margin: 0; font-size: 0.8125rem; line-height: 1.6; -webkit-overflow-scrolling: touch; }
    .docs-pre pre { padding-top: 2.6rem; }
    .docs-prose pre code { background: transparent; border: 0; padding: 0; color: inherit; font-size: inherit; white-space: pre; overflow-wrap: normal; }
    .docs-copy { position: absolute; top: 0.5rem; right: 0.5rem; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.02em; color: #e5e7eb; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22); border-radius: 0.375rem; padding: 0.2rem 0.55rem; cursor: pointer; }
    .docs-copy:hover { background: rgba(255,255,255,0.22); }

    /* HTTP method badges (added server-side around `POST /path` and method table cells). */
    .docs-method { display: inline-block; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.03em; border-radius: 0.3rem; padding: 0.05rem 0.4rem; margin-right: 0.15rem; line-height: 1.5; }
    .docs-method-get { background: #dcfce7; color: #166534; }
    .docs-method-post { background: var(--color-brand-violet-soft); color: var(--color-brand-violet-deep); }
    .docs-method-put, .docs-method-patch { background: #fef3c7; color: #92400e; }
    .docs-method-delete { background: #fee2e2; color: #991b1b; }
    .docs-prose code.docs-endpoint { background: #fff; padding: 0.1rem 0.45rem 0.1rem 0.25rem; }

    .docs-prose table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 1rem 0; font-size: 0.875rem; display: block; overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 0.75rem; }
    .docs-prose th, .docs-prose td { border-bottom: 1px solid #f3f4f6; padding: 0.55rem 0.8rem; text-align: left; vertical-align: top; min-width: 7.5rem; }
    .docs-prose tr:last-child td { border-bottom: 0; }
    .docs-prose td code, .docs-prose th code { overflow-wrap: normal; white-space: nowrap; }
    .docs-prose th { background: #f9fafb; font-weight: 600; white-space: nowrap; color: #111827; border-bottom: 1px solid #e5e7eb; }

    /* Markdown blockquotes read as call-outs. */
    .docs-prose blockquote { margin: 1.1rem 0; padding: 0.75rem 1rem; background: var(--color-brand-violet-soft); border: 1px solid #ddd6fe; border-left: 4px solid var(--color-brand-violet); border-radius: 0.5rem; color: #3730a3; }
    .docs-prose blockquote p { margin: 0.25rem 0; }

    /* Documentation shell: page list + article. Page list stacks above the article on small screens. */
    .docs-layout { display: block; }
    .docs-nav { margin-bottom: 1.25rem; }
    .docs-article { min-width: 0; }
    @media (min-width: 1024px) {
        .docs-layout { display: flex; gap: 2rem; align-items: flex-start; }
        .docs-nav { width: 15rem; flex-shrink: 0; margin-bottom: 0; position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; }
        .docs-article { flex: 1 1 0%; }
        .docs-nav-list { flex-direction: column; }
    }
    @media (max-width: 1023px) {
        .docs-nav-list { display: flex; gap: 0.5rem; overflow-x: auto; padding-bottom: 0.25rem; -webkit-overflow-scrolling: touch; }
        .docs-nav-list a { white-space: nowrap; flex-shrink: 0; }
    }
</style>
