<?php

declare(strict_types=1);

namespace InstallerToolkit\Update\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $backup_id
 * @property string $version_from
 * @property string $version_to
 * @property string $status
 * @property array<string, mixed>|null $manifest
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UpdateHistory extends Model
{
    /** Status: an update was applied successfully. */
    public const STATUS_APPLIED = 'applied';

    /** Status: an update is currently in progress. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** Status: an update failed partway through. */
    public const STATUS_FAILED = 'failed';

    /** Status: an update was successfully rolled back to a prior backup. */
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $table = 'update_history';

    protected $fillable = [
        'backup_id',
        'version_from',
        'version_to',
        'status',
        'manifest',
        'error',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
        ];
    }
}
