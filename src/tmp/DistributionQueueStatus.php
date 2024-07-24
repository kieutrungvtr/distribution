<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class DistributionQueueStatus extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'distribution_queue_status';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'distribution_queue_status_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_STATUS_ID = 'distribution_queue_status_id';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_STATUS_VALUE = 'distribution_queue_status_value';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_STATUS_LOG = 'distribution_queue_status_log';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_STATUS_DATETIME = 'distribution_queue_status_datetime';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_QUEUE_DISTRIBUTION_QUEUE_ID = 'distribution_queue_distribution_queue_id';

    /**
     * @var string
     */
    const COL_UPDATED_AT = 'updated_at';

    /**
     * @var string
     */
    const COL_CREATED_AT = 'created_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'distribution_queue_status';

    #---- Begin custom code -----#
    
    #---- Ended custom code -----#
}