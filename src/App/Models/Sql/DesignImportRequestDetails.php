<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace PLSys\DistrbutionQueue\App\Models\Sql;

use PLSys\DistrbutionQueue\App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class DesignImportRequestDetails extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'design_import_request_details';

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
    const COL_DESIGN_IMPORT_REQUEST_ID = 'design_import_request_id';

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
    const COL_NAME = 'name';

    /**
     * @var string
     */
    const COL_URL = 'url';

    /**
     * @var string
     */
    const COL_DESIGN_URL = 'design_url';

    /**
     * @var string
     */
    const COL_MOCKUPS = 'mockups';

    /**
     * @var string
     */
    const COL_MOCKUPS2 = 'mockups2';

    /**
     * @var string
     */
    const COL_CATEGORY_CATALOG_ID = 'category_catalog_id';

    /**
     * @var string
     */
    const COL_PRODUCT_TYPE_IDS = 'product_type_ids';

    /**
     * @var string
     */
    const COL_SUPPLIER_IDS = 'supplier_ids';

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
    const COL_DESIGN_ID = 'design_id';

    /**
     * @var string
     */
    const COL_CATALOG_ID = 'catalog_id';

    /**
     * @var string
     */
    const COL_CATALOG_SKU = 'catalog_sku';

    /**
     * @var string
     */
    const COL_SIMILAR = 'similar';

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
    const COL_NOTES = 'notes';

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
    const COL_APPROVED_BY = 'approved_by';

    /**
     * @var string
     */
    const COL_APPROVED_AT = 'approved_at';

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
    const TABLE_NAME = 'design_import_request_details';

    #---- Begin custom code -----#
    protected $fillable = ["design_import_request_id", "url", "design_url", "name", "mockups", "category_catalog_id", "design_type_id", "created_by"];

    const STATUS_OPEN = 'open';
    const STATUS_OPPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    #---- Ended custom code -----#
}