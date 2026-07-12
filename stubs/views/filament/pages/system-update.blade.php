<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Current version</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">v{{ $currentVersion }}</p>
            </div>

            <form method="post" action="{{ $launchUrl }}">
                @csrf
                <flux:button variant="primary" type="submit">
                    Launch Updater
                </flux:button>
            </form>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent updates</h2>

            @if (empty($results))
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No updates have been applied yet.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Date</th>
                                <th class="py-2 pr-4 font-medium">Status</th>
                                <th class="py-2 pr-4 font-medium">Version</th>
                                <th class="py-2 pr-4 font-medium">Details</th>
                            </tr>
                        </thead>
                        <tbody class="align-top">
                            @foreach ($results as $result)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                        @if ($result->finishedAt)
                                            {{ \Illuminate\Support\Carbon::parse($result->finishedAt)->toDateTimeString() }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if ($result->applied())
                                            <flux:badge color="success">Applied</flux:badge>
                                        @elseif ($result->rolledBack())
                                            <flux:badge color="gray">Rolled back</flux:badge>
                                        @else
                                            <flux:badge color="danger">{{ ucfirst($result->status) }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                        {{ $result->versionFrom ?: '—' }} → {{ $result->versionTo ?: '—' }}
                                    </td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                        @if ($result->error)
                                            <span class="break-all">{{ $result->failedTask ? $result->failedTask.': ' : '' }}{{ $result->error }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
