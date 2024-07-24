<?php echo "
<?php
/**
 * Warning: This class is generated automatically by schema_update
 *          !!! Do not touch or modify !!!
 */

namespace App\Models\Sql;

use App\Models\BaseModel;
#---- Begin package usage -----#
{$contentUsed}
#---- Ended package usage -----#

class {$className} extends BaseModel
{
    #---- Begin trait -----#
    {$contentUseTrait}
    #---- Ended trait -----#

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = '{$tableName}';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected \$primaryKey = '{$primaryKey}';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public \$incrementing = {$incrementing};

    {$constants}

    /**
     * @const string
     */
    const TABLE_NAME = '{$tableName}';

    #---- Begin custom code -----#
    {$contentDevCode}
    #---- Ended custom code -----#
}";
