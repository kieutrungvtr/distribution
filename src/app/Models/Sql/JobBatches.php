<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class JobBatches extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'job_batches';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    const COL_ID = 'id';

    /**
     * @var string
     */
    const COL_NAME = 'name';

    /**
     * @var string
     */
    const COL_TOTAL_JOBS = 'total_jobs';

    /**
     * @var string
     */
    const COL_PENDING_JOBS = 'pending_jobs';

    /**
     * @var string
     */
    const COL_FAILED_JOBS = 'failed_jobs';

    /**
     * @var string
     */
    const COL_FAILED_JOB_IDS = 'failed_job_ids';

    /**
     * @var string
     */
    const COL_OPTIONS = 'options';

    /**
     * @var string
     */
    const COL_CANCELLED_AT = 'cancelled_at';

    /**
     * @var string
     */
    const COL_CREATED_AT = 'created_at';

    /**
     * @var string
     */
    const COL_FINISHED_AT = 'finished_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'job_batches';

    #---- Begin custom code -----#
    // //
    #---- Ended custom code -----#
}