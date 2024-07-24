<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class Sessions extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sessions';

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
    const COL_USER_ID = 'user_id';

    /**
     * @var string
     */
    const COL_IP_ADDRESS = 'ip_address';

    /**
     * @var string
     */
    const COL_USER_AGENT = 'user_agent';

    /**
     * @var string
     */
    const COL_PAYLOAD = 'payload';

    /**
     * @var string
     */
    const COL_LAST_ACTIVITY = 'last_activity';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'sessions';

    #---- Begin custom code -----#
    // //
    #---- Ended custom code -----#
}