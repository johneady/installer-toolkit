<x-filament-panels::page>
    {{ $this->table }}

    @php($backups = $this->backups)

    @if (count($backups))
        <div class="mt-8">
            <flux:heading size="lg" class="mb-4">Backups on Disk</flux:heading>

            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b border-zinc-200 dark:border-zinc-700">
                            <th class="py-2 px-3 font-medium text-zinc-600 dark:text-zinc-400">Backup ID</th>
                            <th class="py-2 px-3 font-medium text-zinc-600 dark:text-zinc-400">Version</th>
                            <th class="py-2 px-3 font-medium text-zinc-600 dark:text-zinc-400">Date</th>
                            <th class="py-2 px-3 font-medium text-zinc-600 dark:text-zinc-400">Database</th>
                            <th class="py-2 px-3 font-medium text-zinc-600 dark:text-zinc-400">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                <td class="py-2 px-3 font-mono text-xs">{{ $backup['id'] }}</td>
                                <td class="py-2 px-3">{{ $backup['version'] ?? '—' }}</td>
                                <td class="py-2 px-3">
                                    @if ($backup['created_at'])
                                        {{ \Illuminate\Support\Carbon::parse($backup['created_at'])->format('M j, Y g:i A') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 px-3">
                                    @if ($backup['has_database'])
                                        <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400">
                                            <flux:icon name="check-circle" class="size-4" />
                                            Yes
                                        </span>
                                    @else
                                        <span class="text-zinc-400">No</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3">{{ number_format($backup['size'] / 1024 / 1024, 1) }} MB</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
