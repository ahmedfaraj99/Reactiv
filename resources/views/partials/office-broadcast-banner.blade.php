@php
    /**
     * Active office-broadcast banners for the viewing user. A user sees:
     *  - Tenant-wide broadcasts (office_id is NULL) posted by the owner.
     *  - Broadcasts targeting an office they belong to (employee /
     *    supervisor / owner-with-office).
     *  - For managers: broadcasts targeting any office they manage.
     * Ordered so the most-urgent level surfaces first.
     */
    $user   = auth()->user();
    $tenant = filament()->getTenant();
    $broadcasts = collect();

    if ($user && $tenant) {
        $officeIds = [];
        if ($user->office_id) {
            $officeIds[] = $user->office_id;
        }
        if (method_exists($user, 'isManager') && $user->isManager()) {
            $officeIds = array_merge($officeIds, $user->managedOffices()->pluck('id')->all());
        }
        $officeIds = array_values(array_unique($officeIds));

        $broadcasts = \App\Models\OfficeBroadcast::query()
            ->active()
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($officeIds): void {
                $q->whereNull('office_id');
                if (! empty($officeIds)) {
                    $q->orWhereIn('office_id', $officeIds);
                }
            })
            ->orderByRaw("CASE level WHEN 'danger' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();
    }
@endphp

@foreach ($broadcasts as $broadcast)
    @php
        $chrome = match ($broadcast->level) {
            \App\Models\OfficeBroadcast::LEVEL_DANGER => [
                'border' => 'border-rose-600 dark:border-rose-500',
                'bg'     => 'bg-rose-100 dark:bg-rose-500/20',
                'title'  => 'text-rose-900 dark:text-rose-200',
                'body'   => 'text-rose-800 dark:text-rose-100',
                'sub'    => 'text-rose-700 dark:text-rose-200',
                'icon'   => 'text-rose-700 dark:text-rose-300',
            ],
            \App\Models\OfficeBroadcast::LEVEL_WARNING => [
                'border' => 'border-amber-600 dark:border-amber-500',
                'bg'     => 'bg-amber-100 dark:bg-amber-500/20',
                'title'  => 'text-amber-900 dark:text-amber-200',
                'body'   => 'text-amber-800 dark:text-amber-100',
                'sub'    => 'text-amber-700 dark:text-amber-200',
                'icon'   => 'text-amber-700 dark:text-amber-300',
            ],
            default => [
                'border' => 'border-sky-600 dark:border-sky-500',
                'bg'     => 'bg-sky-100 dark:bg-sky-500/20',
                'title'  => 'text-sky-900 dark:text-sky-200',
                'body'   => 'text-sky-800 dark:text-sky-100',
                'sub'    => 'text-sky-700 dark:text-sky-200',
                'icon'   => 'text-sky-700 dark:text-sky-300',
            ],
        };
    @endphp

    <div class="flex items-start gap-3 border-b-4 {{ $chrome['border'] }} {{ $chrome['bg'] }} px-4 py-3">
        <x-heroicon-o-megaphone class="h-6 w-6 flex-shrink-0 {{ $chrome['icon'] }}" />
        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold {{ $chrome['title'] }} break-words">
                {{ $broadcast->message }}
            </p>
            <p class="mt-0.5 text-xs {{ $chrome['sub'] }}">
                @if ($broadcast->sender)
                    — {{ $broadcast->sender->name }}
                @endif
                @if ($broadcast->office)
                    · {{ $broadcast->office->name }}
                @else
                    · لكل المكاتب
                @endif
                @if ($broadcast->expires_at)
                    · ينتهي {{ $broadcast->expires_at->diffForHumans() }}
                @endif
            </p>
        </div>
    </div>
@endforeach
