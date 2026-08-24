@php
    // For random passwords/backup codes, splitting every 4 chars lets the
    // employee keep their place while typing on a PS controller. For
    // strings with natural structure (emails: local@domain.tld with `-`
    // and `.`), the mechanical every-4 split cuts across delimiters and
    // makes the value HARDER to read (e.g. `demo -acc -1@e xamp le.c om`).
    // Callers pass `chunked` = false for anything with intrinsic structure.
    $value = (string) $value;
    $chars = mb_str_split($value);
    $chunked = $chunked ?? true;
    $chunks = $chunked ? array_chunk($chars, 4) : [$chars];

    // Letters that get mistaken for digits on a TV screen — these get an
    // extra colored ring so `O` doesn't get typed as `0`, and vice versa.
    $confusableLetters = ['O', 'o', 'l', 'I', 'S', 'Z', 'B', 'G', 'g'];

    $color = $color ?? 'gray';
    $id = $id ?? 'cred-' . uniqid();
@endphp
<div class="fc-cred-row space-y-1.5">
    <code
        id="{{ $id }}"
        data-fc-cred
        class="fc-cred block w-full select-all break-all rounded-lg bg-white px-3 py-2 font-mono text-gray-950 ring-1 ring-inset ring-gray-200 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10"
    >
        @foreach ($chunks as $ci => $chunk)
            <span class="fc-cred-chunk">@foreach ($chunk as $ch)@php
                    $isDigit = ctype_digit($ch);
                    $isConfusable = in_array($ch, $confusableLetters, true);
                    $classes = 'fc-cred-char';
                    if ($isDigit) {
                        $classes .= ' fc-cred-digit';
                    } elseif ($isConfusable) {
                        $classes .= ' fc-cred-letter';
                    }
                    $title = null;
                    if ($isDigit) {
                        $title = 'رقم';
                    } elseif ($isConfusable) {
                        $title = 'حرف ' . $ch . (ctype_upper($ch) ? ' كبير' : ' صغير');
                    }
                @endphp<span class="{{ $classes }}"@if ($title) title="{{ $title }}"@endif>{{ $ch }}</span>@endforeach</span>
        @endforeach
    </code>
    <div class="flex justify-end gap-2">
    <button
        type="button"
        class="fc-cred-speak inline-flex h-8 items-center justify-center rounded-md bg-gray-100 px-2 text-gray-600 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10"
        data-fc-speak-for="{{ $id }}"
        title="استمع للحروف واحداً واحداً"
        aria-label="استمع"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
            <path d="M10 3.75a.75.75 0 0 0-1.264-.546L4.454 7H2.75A1.75 1.75 0 0 0 1 8.75v2.5c0 .966.784 1.75 1.75 1.75h1.704l4.282 3.796A.75.75 0 0 0 10 16.25V3.75Z"/>
            <path d="M13.24 5.42a.75.75 0 0 0-1.06 1.06 4.5 4.5 0 0 1 0 6.36.75.75 0 1 0 1.06 1.06 6 6 0 0 0 0-8.48Z"/>
        </svg>
    </button>
    <button
        type="button"
        class="fc-cred-bigscreen inline-flex h-8 items-center justify-center rounded-md bg-gray-100 px-2 text-gray-600 transition hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10"
        data-fc-bigscreen-for="{{ $id }}"
        title="عرض على شاشة كاملة"
        aria-label="عرض على شاشة كاملة"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
            <path d="M3 4.75A1.75 1.75 0 0 1 4.75 3h3.5a.75.75 0 0 1 0 1.5h-3.5a.25.25 0 0 0-.25.25v3.5a.75.75 0 0 1-1.5 0v-3.5ZM11 3.75A.75.75 0 0 1 11.75 3h3.5A1.75 1.75 0 0 1 17 4.75v3.5a.75.75 0 0 1-1.5 0v-3.5a.25.25 0 0 0-.25-.25h-3.5a.75.75 0 0 1-.75-.75ZM3.75 11a.75.75 0 0 1 .75.75v3.5c0 .138.112.25.25.25h3.5a.75.75 0 0 1 0 1.5h-3.5A1.75 1.75 0 0 1 3 15.25v-3.5a.75.75 0 0 1 .75-.75ZM16.25 11a.75.75 0 0 1 .75.75v3.5A1.75 1.75 0 0 1 15.25 17h-3.5a.75.75 0 0 1 0-1.5h3.5a.25.25 0 0 0 .25-.25v-3.5a.75.75 0 0 1 .75-.75Z"/>
        </svg>
    </button>
    </div>
</div>
