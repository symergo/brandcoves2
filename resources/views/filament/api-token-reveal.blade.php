{{--
  The one moment the plaintext key exists.

  Only its SHA-256 is stored, so this modal cannot be reopened and the value
  cannot be recovered. That is why the modal refuses to close on a stray click
  or an Escape, and why the copy button is the loudest thing in it.
--}}
<div class="space-y-4" x-data="{ copied: false }">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        The key for <strong class="font-medium text-gray-950 dark:text-white">{{ $name }}</strong>.
        It is shown once — only its hash is stored, so there is no way to print it again.
        Losing it means minting a new one.
    </p>

    <div class="flex items-stretch gap-2">
        <code
            class="min-w-0 flex-1 select-all break-all rounded-lg bg-gray-50 p-3 font-mono text-sm text-gray-950 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/10"
            x-ref="token"
        >{{ $token }}</code>

        <x-filament::button
            x-on:click="navigator.clipboard.writeText($refs.token.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"
            icon="heroicon-o-clipboard"
            color="gray"
        >
            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
        </x-filament::button>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Store it as an environment variable named <code class="font-mono">BRANDCOVES_API_KEY</code>
        wherever it will be used. Not in a file in the repository — that folder is synced.
    </p>

    @if ($canPublish)
        {{--
          Said here rather than only at the ticking of the box: this is the last
          screen before the key leaves, and it is the point at which someone is
          most likely to notice they granted more than they meant to.
        --}}
        <x-filament::section :heading="'This key can publish'" icon="heroicon-o-exclamation-triangle">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Anything it writes can reach readers without a person seeing it first. If that was not
                the intention, close this and change its abilities — the key stays valid, only what it
                may do changes.
            </p>
        </x-filament::section>
    @endif
</div>
