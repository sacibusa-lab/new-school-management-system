{{--
    Applies the saved theme before anything paints.

    This has to be inline and in the head: a class added after the stylesheet loads
    shows the light page for a frame first, which is a white flash on every
    navigation for somebody who chose dark. It cannot live in the bundled script,
    for the same reason.

    Somebody who has never chosen gets the theme their system already uses, so a
    machine set to dark opens dark rather than needing to be told twice.
--}}
<script>
    (function () {
        try {
            var saved = localStorage.getItem('saci-theme');
            var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;

            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {
            /* Private mode, or storage denied: light is a fine answer. */
        }
    })();
</script>
