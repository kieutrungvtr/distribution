<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class Cache extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cache';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'key';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    const COL_KEY = 'key';

    /**
     * @var string
     */
    const COL_VALUE = 'value';

    /**
     * @var string
     */
    const COL_EXPIRATION = 'expiration';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'cache';

    #---- Begin custom code -----#
    // //
    #---- Ended custom code -----#
}