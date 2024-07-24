<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class Jobs extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'jobs';

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
    const COL_QUEUE = 'queue';

    /**
     * @var string
     */
    const COL_PAYLOAD = 'payload';

    /**
     * @var string
     */
    const COL_ATTEMPTS = 'attempts';

    /**
     * @var string
     */
    const COL_RESERVED_AT = 'reserved_at';

    /**
     * @var string
     */
    const COL_AVAILABLE_AT = 'available_at';

    /**
     * @var string
     */
    const COL_CREATED_AT = 'created_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'jobs';

    #---- Begin custom code -----#
    // //
    #---- Ended custom code -----#
}