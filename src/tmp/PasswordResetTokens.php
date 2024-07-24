<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class PasswordResetTokens extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'password_reset_tokens';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'email';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    const COL_EMAIL = 'email';

    /**
     * @var string
     */
    const COL_TOKEN = 'token';

    /**
     * @var string
     */
    const COL_CREATED_AT = 'created_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'password_reset_tokens';

    #---- Begin custom code -----#
    // //
    #---- Ended custom code -----#
}