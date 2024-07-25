<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace PLSys\DistrbutionQueue\App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#

#---- Ended package usage -----#

class DistributionStates extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'distribution_states';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'distribution_state_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var string
     */
    const COL_DISTRIBUTION_STATE_ID = 'distribution_state_id';

    /**
     * @var string
     */
    const COL_FK_DISTRIBUTION_ID = 'fk_distribution_id';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_STATE_VALUE = 'distribution_state_value';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_STATE_LOG = 'distribution_state_log';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_STATE_EXCEPTION = 'distribution_state_exception';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_STATE_CREATED_AT = 'distribution_state_created_at';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_STATE_UPDATED_AT = 'distribution_state_updated_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'distribution_states';

    #---- Begin custom code -----#
    protected $fillable = [
        self::COL_FK_DISTRIBUTION_ID,
        self::COL_DISTRIBUTION_STATE_VALUE,
        self::COL_DISTRIBUTION_STATE_LOG,
        self::COL_DISTRIBUTION_STATE_EXCEPTION,
        self::COL_DISTRIBUTION_STATE_CREATED_AT
    ];

    public $timestamps = false;

    const DISTRIBUTION_STATES_INIT = 'initial';
    const DISTRIBUTION_STATES_PUSHED = 'pushed';
    const DISTRIBUTION_STATES_PROCESSING = 'processing';
    const DISTRIBUTION_STATES_FAILED = 'failed';
    const DISTRIBUTION_STATES_COMPLETED = 'completed';

    public function distributions()
    {
        return $this->belongsTo(Distributions::class, 'distribution_id', 'fk_distribution_id');
    }
    #---- Ended custom code -----#
}