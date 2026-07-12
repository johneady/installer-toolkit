<x-filament-panels::page>
    @php($routeBase = config('updates.routes.name', 'chunked-upload'))

    <div
        x-data="{
            uploading: false,
            progress: 0,
            fileName: '',
            chunkSize: 1024 * 1024,
            async startUpload(event) {
                const file = event.target.files[0]
                if (!file) return

                if (!file.name.endsWith('.update')) {
                    $wire.set('errorMessage', 'Please select a .update file.')
                    event.target.value = ''
                    return
                }

                this.fileName = file.name
                this.uploading = true
                this.progress = 0
                $wire.set('isUploading', true)
                $wire.set('errorMessage', null)

                const totalChunks = Math.ceil(file.size / this.chunkSize)

                try {
                    const initResponse = await fetch('{{ route($routeBase.'.initialize') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            fileName: file.name,
                            fileSize: file.size,
                            totalChunks: totalChunks,
                        }),
                    })

                    if (!initResponse.ok) {
                        throw new Error('Failed to initialize upload.')
                    }

                    const { uploadId } = await initResponse.json()

                    for (let i = 0; i < totalChunks; i++) {
                        const start = i * this.chunkSize
                        const end = Math.min(start + this.chunkSize, file.size)
                        const chunk = file.slice(start, end)

                        const formData = new FormData()
                        formData.append('uploadId', uploadId)
                        formData.append('chunkIndex', i)
                        formData.append('chunk', chunk, `chunk_${i}`)

                        const chunkResponse = await fetch('{{ route($routeBase.'.chunk') }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            },
                            body: formData,
                        })

                        if (!chunkResponse.ok) {
                            throw new Error(`Failed to upload chunk ${i + 1} of ${totalChunks}.`)
                        }

                        this.progress = Math.round(((i + 1) / totalChunks) * 100)
                        $wire.set('uploadProgress', this.progress)
                    }

                    const assembleResponse = await fetch('{{ route($routeBase.'.assemble') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({ uploadId: uploadId }),
                    })

                    if (!assembleResponse.ok) {
                        throw new Error('Failed to assemble uploaded file.')
                    }

                    this.uploading = false
                    $wire.uploadComplete(uploadId)
                } catch (error) {
                    this.uploading = false
                    this.progress = 0
                    $wire.uploadFailed(error.message || 'Upload failed. Please try again.')
                }

                event.target.value = ''
            },
        }"
        x-on:continue-update.window="$wire.runNextStep()"
        x-on:continue-extraction.window="$wire.continueExtraction()"
    >
        {{-- Current Version --}}
        <div class="mb-6">
            <flux:heading size="lg">Current Version: v{{ config('app.version') }}</flux:heading>
        </div>

        {{-- Backup Warning Banner --}}
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.heading>Please back up before upgrading</flux:callout.heading>
            <flux:text class="mt-1">
                Although this updater creates an automatic backup of your files and database,
                we still strongly recommend taking your own complete backup first. Without a
                backup, recovery may not be possible if something goes wrong.
            </flux:text>
        </flux:callout>

        {{-- Error Message --}}
        @if ($errorMessage)
            <flux:callout variant="danger" class="mb-6">
                <flux:text>{{ $errorMessage }}</flux:text>
            </flux:callout>
        @endif

        {{-- Update Complete --}}
        @if ($updateComplete)
            <flux:callout variant="success" class="mb-6">
                <flux:text>Update to v{{ $updateVersion }} completed successfully! Please reload the page.</flux:text>
            </flux:callout>

            <flux:button variant="primary" onclick="window.location.reload()">
                Reload Page
            </flux:button>
        @elseif ($isUpdating)
            {{-- Update Progress --}}
            <div class="space-y-3">
                <flux:heading size="lg">Updating to v{{ $updateVersion }}</flux:heading>
                <flux:text class="mb-4">Do not close this page or navigate away during the update.</flux:text>

                <div class="space-y-2 mt-4">
                    @foreach ($steps as $index => $step)
                        <div class="flex items-center gap-3 p-3 rounded-lg {{ $step['status'] === 'failed' ? 'bg-red-50 dark:bg-red-950/20' : ($step['status'] === 'complete' ? 'bg-green-50 dark:bg-green-950/20' : 'bg-gray-50 dark:bg-gray-800') }}">
                            {{-- Status Icon --}}
                            <div class="shrink-0">
                                @if ($step['status'] === 'complete')
                                    <flux:icon name="check-circle" class="size-5 text-green-500" />
                                @elseif ($step['status'] === 'running')
                                    <flux:icon name="arrow-path" class="size-5 text-blue-500 animate-spin" />
                                @elseif ($step['status'] === 'failed')
                                    <flux:icon name="x-circle" class="size-5 text-red-500" />
                                @else
                                    <flux:icon name="clock" class="size-5 text-gray-400" />
                                @endif
                            </div>

                            {{-- Step Label --}}
                            <div class="grow">
                                <flux:text class="font-medium">{{ $step['label'] }}</flux:text>
                                @if ($step['detail'])
                                    <flux:text class="text-sm text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Extraction Progress Bar --}}
                @if ($totalFiles > 0 && $extractedFiles < $totalFiles)
                    <div class="mt-4">
                        <div class="flex justify-between mb-1">
                            <flux:text class="text-sm">Extracting files...</flux:text>
                            <flux:text class="text-sm">{{ round(($extractedFiles / $totalFiles) * 100) }}%</flux:text>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div
                                class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                                style="width: {{ round(($extractedFiles / $totalFiles) * 100) }}%"
                            ></div>
                        </div>
                    </div>
                @endif
            </div>
        @elseif ($validationResult?->valid)
            {{-- Validation Passed - Ready to Update --}}
            <flux:callout variant="success" class="mb-6">
                <flux:text>
                    Update package validated. Ready to update from
                    <strong>v{{ $validationResult->currentVersion }}</strong> to
                    <strong>v{{ $validationResult->version }}</strong>.
                </flux:text>
                @if ($validationResult->filesCount)
                    <flux:text class="text-sm mt-1">{{ number_format($validationResult->filesCount) }} files in package</flux:text>
                @endif
            </flux:callout>

            <div class="flex gap-3">
                <flux:button variant="primary" wire:click="startUpdate">Begin Update</flux:button>

                <flux:button variant="ghost" wire:click="resetState">Cancel</flux:button>
            </div>
        @else
            {{-- Upload Form --}}
            <div class="space-y-4">
                <flux:text>Upload an update package (.update) to update your application.</flux:text>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Update Package
                    </label>
                    <input
                        type="file"
                        accept=".update"
                        x-on:change="startUpload($event)"
                        :disabled="uploading"
                        class="block w-full text-sm text-gray-500 dark:text-gray-400
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-lg file:border-0
                            file:text-sm file:font-semibold
                            file:bg-blue-50 file:text-blue-700
                            dark:file:bg-blue-950 dark:file:text-blue-300
                            hover:file:bg-blue-100 dark:hover:file:bg-blue-900
                            disabled:opacity-50 disabled:cursor-not-allowed"
                    />
                </div>

                {{-- Upload Progress --}}
                <div x-show="uploading" x-cloak>
                    <div class="flex justify-between mb-1">
                        <flux:text class="text-sm text-blue-600 dark:text-blue-400">
                            Uploading <span x-text="fileName"></span>...
                        </flux:text>
                        <flux:text class="text-sm text-blue-600 dark:text-blue-400">
                            <span x-text="progress"></span>%
                        </flux:text>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div
                            class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                            :style="'width: ' + progress + '%'"
                        ></div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
