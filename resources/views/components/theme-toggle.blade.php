@props([
    'class' => null,
])

{{--
    Light / dark switch.

    The choice is remembered on the machine rather than stored against the user,
    because the office keeps shared machines: the person who prefers dark is not
    always the person signed in, and a shared terminal that flips theme depending
    on who logged in and then back again would be worse than either.

    The theme is applied by the inline script in the layout, before the page
    paints, so nothing flashes. This component only flips the class and records
    the choice.
--}}
<button type="button"
        {{ $attributes->merge(['class' => 'rounded-xl p-2 text-ink-soft transition hover:bg-surface-3 hover:text-ink ' . $class]) }}
        x-data="{
            dark: document.documentElement.classList.contains('dark'),
            toggle() {
                this.dark = ! this.dark;
                document.documentElement.classList.toggle('dark', this.dark);
                try { localStorage.setItem('saci-theme', this.dark ? 'dark' : 'light'); } catch (e) {}
            },
        }"
        @click="toggle()"
        :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
        :title="dark ? 'Switch to light mode' : 'Switch to dark mode'">
    {{-- The moon is shown while the page is light: it is what pressing it gets you.
         Both are x-cloak: before Alpine loads, x-show has run on neither of them and
         a button offering two icons at once is worse than one offering none. --}}
    <svg x-show="! dark" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/>
    </svg>

    <svg x-show="dark" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>
    </svg>
</button>
