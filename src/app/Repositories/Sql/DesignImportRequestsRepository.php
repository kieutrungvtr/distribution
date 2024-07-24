<?php

namespace App\Repositories\Sql;

use App\Models\Sql\DesignImportRequestDetails;
use App\Models\Sql\DesignImportRequests;
use App\Repositories\BaseSqlRepository;
use Illuminate\Support\Facades\DB;

class DesignImportRequestsRepository extends BaseSqlRepository
{
    public function getModel()
    {
        return DesignImportRequests::class;
    }

    public function getByRequestId($requestId)
    {
        $data = DesignImportRequests::leftJoin(DesignImportRequestDetails::TABLE_NAME, function($join) {
            $join->on(
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_ID),
                '=',
                sprintf("%s.%s", DesignImportRequestDetails::TABLE_NAME, DesignImportRequestDetails::COL_DESIGN_IMPORT_REQUEST_ID)
            );
        })
        ->where(
            [
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_ID) => $requestId
            ]
        )
        ->select(
            [
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_CATEGORY_CATALOG_ID),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_DESIGN_TYPE_ID),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_TYPE_AMZ),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_MBA_IDS),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_COLOR_CATALOG_ID),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_RULE_ID),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_CREATED_BY),
                sprintf("%s.%s", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_FOLDER_URL),
                sprintf("%s.%s", DesignImportRequestDetails::TABLE_NAME, DesignImportRequestDetails::COL_DESIGN_IMPORT_REQUEST_ID),
                sprintf("%s.%s as design_status", DesignImportRequests::TABLE_NAME, DesignImportRequests::COL_STATUS),
                sprintf("%s.%s as design_details_status", DesignImportRequestDetails::TABLE_NAME, DesignImportRequestDetails::COL_STATUS),
            ]
        )
        ->first();

        return $data;
    
    }

}
