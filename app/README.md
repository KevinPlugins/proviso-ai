# Admin application

Vue 3 single-page app for the plugin's admin screen. All data comes from the
`mag/v1` REST namespace; the PHP side only mounts `<div id="mag-app">` and
passes the REST root and nonce.

    npm install
    npm run build     # bundles to ../assets/app.js and ../assets/app.css
    npm run dev       # same, rebuilding on change

Output is committed so the distributed plugin needs no build step and no CDN,
which is also what WordPress.org review expects.

## Layout

    src/main.js               mount
    src/App.vue               shell, tabs, toast
    src/store.js              reactive store + REST client
    src/style.css             design tokens and base styles
    src/components/           KindTag, DecisionTag, ApproverPicker
    src/views/                Abilities, Queue, Audit, Settings

## The read/write tag

`KindTag` renders the classification returned by `Policy::classify()`. A tag
with a solid fill was earned by observation; a dashed outline means the
classification is inferred from the ability's own declaration or its name and
has not been verified yet. That distinction is load-bearing — do not flatten it.
