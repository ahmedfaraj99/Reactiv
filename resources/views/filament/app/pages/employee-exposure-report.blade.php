<x-filament-panels::page>
    <div class="mb-6 rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">اختر الموظف</label>
        <select
            wire:model.live="employeeId"
            class="fi-select-input block w-full rounded-lg border-none bg-white py-1.5 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:max-w-sm"
        >
            <option value="">— اختر —</option>
            @foreach ($this->employeeOptions() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">يعرض كل حساب كشف هذا الموظف بريده وكلمة مروره فعلياً — للمراجعة الأمنية عند مغادرة موظف أو الاشتباه به.</p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
