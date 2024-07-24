<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class Users extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

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
    const COL_NAME = 'name';

    /**
     * @var string
     */
    const COL_EMAIL = 'email';

    /**
     * @var string
     */
    const COL_EMAIL_VERIFIED_AT = 'email_verified_at';

    /**
     * @var string
     */
    const COL_PASSWORD = 'password';

    /**
     * @var string
     */
    const COL_REMEMBER_TOKEN = 'remember_token';

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
    const TABLE_NAME = 'users';

    #---- Begin custom code -----#
    // //
    #---- Ended custom code -----#
}