<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#
use Illuminate\Database\Eloquent\Relations\HasMany;
#---- Ended package usage -----#

class DistributionQueue extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'distribution_queue';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'distribution_queue_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_ID = 'distribution_queue_id';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_REQUEST = 'distribution_queue_request';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_PAYLOAD = 'distribution_queue_payload';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_PRIORITY = 'distribution_queue_priority';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_STATUS = 'distribution_queue_status';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_CREATED_AT = 'distribution_queue_created_at';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_RETRY = 'distribution_queue_retry';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_PUSHED_AT = 'distribution_queue_pushed_at';

    /**
     * @var string
     */
    const COL_UPDATED_AT = 'updated_at';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_JOB_NAME = 'distribution_queue_job_name';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_INIT_AT = 'distribution_queue_init_at';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_FAILED_AT = 'distribution_queue_failed_at';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_FINISH_AT = 'distribution_queue_finish_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'distribution_queue';

    #---- Begin custom code -----#
    protected $fillable = ["distribution_queue_id", "distribution_queue_status", "distribution_queue_finish_at",
     "distribution_queue_failed_at", "distribution_queue_init_at"];

    const DISTRIBUTION_QUEUE_STATUS_INIT = 'init';
    const DISTRIBUTION_QUEUE_STATUS_PUSHED = 'pushed';
    const DISTRIBUTION_QUEUE_STATUS_FINISH = 'finish';
    const DISTRIBUTION_QUEUE_STATUS_FAILED = 'failed';

    /**
     * Get the status for the distirbution queue.
     */
    public function status(): HasMany
    {
        return $this->hasMany(DistributionQueueStatus::class);
    }
    #---- Ended custom code -----#
}