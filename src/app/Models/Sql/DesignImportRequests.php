<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace PLSys\DistrbutionQueue\App\Models\Sql;

use PLSys\DistrbutionQueue\App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class DesignImportRequests extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'design_import_requests';

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
    public $incrementing = true;

    /**
     * @var string
     */
    const COL_ID = 'id';

    /**
     * @var string
     */
    const COL_STARTED_AT = 'started_at';

    /**
     * @var string
     */
    const COL_FINISHED_AT = 'finished_at';

    /**
     * @var string
     */
    const COL_FOLDER_URL = 'folder_url';

    /**
     * @var string
     */
    const COL_DESCRIPTION = 'description';

    /**
     * @var string
     */
    const COL_TOTAL = 'total';

    /**
     * @var string
     */
    const COL_REJECTED = 'rejected';

    /**
     * @var string
     */
    const COL_APPROVED = 'approved';

    /**
     * @var string
     */
    const COL_PROCESSING = 'processing';

    /**
     * @var string
     */
    const COL_COMPLETED = 'completed';

    /**
     * @var string
     */
    const COL_FAILED = 'failed';

    /**
     * @var string
     */
    const COL_CATEGORY_CATALOG_ID = 'category_catalog_id';

    /**
     * @var string
     */
    const COL_TEMPLATE_ID = 'template_id';

    /**
     * @var string
     */
    const COL_DESIGN_TYPE_ID = 'design_type_id';

    /**
     * @var string
     */
    const COL_TYPE_AMZ = 'type_amz';

    /**
     * @var string
     */
    const COL_MBA_IDS = 'mba_ids';

    /**
     * @var string
     */
    const COL_COLOR_CATALOG_ID = 'color_catalog_id';

    /**
     * @var string
     */
    const COL_RULE_ID = 'rule_id';

    /**
     * @var string
     */
    const COL_STATUS = 'status';

    /**
     * @var string
     */
    const COL_LOGS = 'logs';

    /**
     * @var string
     */
    const COL_TYPE = 'type';

    /**
     * @var string
     */
    const COL_CREATED_BY = 'created_by';

    /**
     * @var string
     */
    const COL_USER_ID = 'user_id';

    /**
     * @var string
     */
    const COL_DELETED_AT = 'deleted_at';

    /**
     * @var string
     */
    const COL_CREATED_AT = 'created_at';

    /**
     * @var string
     */
    const COL_UPDATED_AT = 'updated_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'design_import_requests';

    #---- Begin custom code -----#
    const STATUS_READ_FAILED = 'read_failed';
    #---- Ended custom code -----#
}