<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class Flights extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'flights';

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
    const COL_CREATED_AT = 'created_at';

    /**
     * @var string
     */
    const COL_UPDATED_AT = 'updated_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'flights';

    #---- Begin custom code -----#
    
    #---- Ended custom code -----#
}