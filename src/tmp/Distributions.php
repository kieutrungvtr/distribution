<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#
use Illuminate\Database\Eloquent\Relations\HasMany;
#---- Ended package usage -----#

class Distributions extends BaseModel
{
    #---- Begin trait -----#
    
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'distributions';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'distribution_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var string
     */
    const COL_DISTRIBUTION_ID = 'distribution_id';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_REQUEST_ID = 'distribution_request_id';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_PAYLOAD = 'distribution_payload';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_JOB_NAME = 'distribution_job_name';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_PRIORITY = 'distribution_priority';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_CREATED_BY = 'distribution_created_by';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_CREATED_AT = 'distribution_created_at';

    /**
     * @var string
     */
    const COL_DISTRIBUTION_UPDATED_AT = 'distribution_updated_at';

    

    /**
     * @const string
     */
    const TABLE_NAME = 'distributions';

    #---- Begin custom code -----#
    protected $fillable = [
        self::COL_DISTRIBUTION_REQUEST_ID,
        self::COL_DISTRIBUTION_PAYLOAD,
        self::COL_DISTRIBUTION_JOB_NAME,
        self::COL_DISTRIBUTION_CREATED_AT
    ];

    public $timestamps = false;

    /**
     * Get the states for the distirbution.
     */
    public function states(): HasMany
    {
        return $this->hasMany(DistributionStates::class, DistributionStates::COL_FK_DISTRIBUTION_ID);
    }
    #---- Ended custom code -----#
}