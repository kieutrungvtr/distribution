<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class DistributionRequest extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'distribution_request';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'distribution_request_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUEST_ID = 'distribution_request_id';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUEST_STATUS = 'distribution_request_status';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUEST_LOG = 'distribution_request_log';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUEST_DATETIME = 'distribution_request_datetime';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUESTCOL = 'distribution_requestcol';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUEST_UUID = 'distribution_request_uuid';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'distribution_request';

    #---- Begin custom code -----#
    protected $fillable = ["distribution_request_status", "distribution_request_uuid", "distribution_request_log",
     "distribution_request_datetime"];
    
    #---- Ended custom code -----#
}