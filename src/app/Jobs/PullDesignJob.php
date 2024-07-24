<?php

namespace App\Jobs;

use App\Libs\GoogleDrive;
use App\Models\Sql\DesignImportRequestDetails;
use App\Models\Sql\DesignImportRequests;
use App\Models\Sql\Distributions;
use App\Models\Sql\DistributionStates;
use App\Repositories\Sql\DesignImportRequestsRepository;
use App\Services\PushingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PullDesignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const FOLDER_ROOT           = 'Root';
    const FOLDER_DESIGN         = 'Design';
    const FOLDER_MOCKUP         = 'Mockup';
    const FOLDER_MOCKUP_2       = 'Mockup2';
    const ERROR_FOLDER_EMPTY    = 'Folder %s is empty !';
    const ERROR_FOLDER_DUP      = 'Folder %s is duplicate !';
    const ERROR_FILE_NF         = 'File %s not found !';

    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function middleware()
    {
        return [(new WithoutOverlapping($this->data[Distributions::COL_DISTRIBUTION_ID]))->dontRelease()];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $pushingService = new PushingService();
            $distributionId = $this->data[Distributions::COL_DISTRIBUTION_ID];
            $pushingService->post($distributionId, DistributionStates::DISTRIBUTION_STATES_PROCESSING);

            $requestId = $this->data[Distributions::COL_DISTRIBUTION_REQUEST_ID];
            $designImportRequestsRepository = new DesignImportRequestsRepository();
            $designData = $designImportRequestsRepository->getByRequestId($requestId);
            if ($designData->design_details_status && $designData->design_details_status !== DesignImportRequestDetails::STATUS_FAILED) {
                throw new Exception('Design status invalid to processing');
            }
            //$this->categories => API production
            // $productType = array_filter($this->categories, function ($category) use ($designData) {
            //     return $category['id'] == $designData->{DesignImportRequests::COL_CATEGORY_CATALOG_ID};
            // });
            // $productType = !empty($productType) ? reset($productType) : [];
            // $productTypeId = !empty($productType) ? data_get($productType, 'product_type.id') : null;
            $folderRoot = Str::after($designData->{DesignImportRequests::COL_FOLDER_URL} ?? '', GoogleDrive::FOLDER);
            $folderRootParts = explode('?', $folderRoot);
            $folderId = reset($folderRootParts);
            $designs = GoogleDrive::listFiles($folderId);
            if (empty($designs)) {
                throw new \Exception(sprintf(self::ERROR_FOLDER_EMPTY, self::FOLDER_DESIGN));
            }
            foreach ($designs as $key => $design) {
                //Design
                $designName = $design['name'];
                $fileName = pathinfo(Str::squish($designName), PATHINFO_FILENAME);
                $name = Str::slug($fileName);
                $designUrl = "import/designs/$requestId/$name.png";
                $file = GoogleDrive::getFile($design['id']);
                if (empty($file)) {
                    throw new \Exception(sprintf(self::ERROR_FOLDER_EMPTY, self::FOLDER_DESIGN));
                }
                Storage::disk('do')->put($designUrl, $file->getBody()->getContents());
                $designImportRequestDetailsData[$key] = [
                        DesignImportRequestDetails::COL_DESIGN_IMPORT_REQUEST_ID  => $requestId,
                        DesignImportRequestDetails::COL_URL                       => $design['id'],
                        DesignImportRequestDetails::COL_NAME                  => $fileName,
                        DesignImportRequestDetails::COL_DESIGN_URL            => $designUrl,
                        DesignImportRequestDetails::COL_MOCKUPS               => json_encode([]),
                        DesignImportRequestDetails::COL_CATEGORY_CATALOG_ID   => $designData->{DesignImportRequests::COL_CATEGORY_CATALOG_ID},
                        DesignImportRequestDetails::COL_PRODUCT_TYPE_IDS      => null,
                        DesignImportRequestDetails::COL_SUPPLIER_IDS          => null,
                        DesignImportRequestDetails::COL_DESIGN_TYPE_ID        => $designData->{DesignImportRequests::COL_DESIGN_TYPE_ID},
                        DesignImportRequestDetails::COL_TYPE_AMZ              => $designData->{DesignImportRequests::COL_TYPE_AMZ},
                        DesignImportRequestDetails::COL_MBA_IDS               => $designData->{DesignImportRequests::COL_MBA_IDS},
                        DesignImportRequestDetails::COL_COLOR_CATALOG_ID      => $designData->{DesignImportRequests::COL_COLOR_CATALOG_ID},
                        DesignImportRequestDetails::COL_RULE_ID               => $designData->{DesignImportRequests::COL_RULE_ID},
                        DesignImportRequestDetails::COL_STATUS                => DesignImportRequestDetails::STATUS_OPEN,
                        DesignImportRequestDetails::COL_CREATED_BY            => $designData->{DesignImportRequests::COL_CREATED_BY}
                ];
            }
            $response = DesignImportRequestDetails::upsert(
                $designImportRequestDetailsData,
                uniqueBy: [
                    DesignImportRequestDetails::COL_DESIGN_IMPORT_REQUEST_ID,
                    DesignImportRequestDetails::COL_URL
                ], 
                update: [
                    DesignImportRequestDetails::COL_NAME,                
                    DesignImportRequestDetails::COL_DESIGN_URL,           
                    DesignImportRequestDetails::COL_MOCKUPS,
                    DesignImportRequestDetails::COL_CATEGORY_CATALOG_ID,
                    DesignImportRequestDetails::COL_PRODUCT_TYPE_IDS,
                    DesignImportRequestDetails::COL_SUPPLIER_IDS,
                    DesignImportRequestDetails::COL_DESIGN_TYPE_ID,
                    DesignImportRequestDetails::COL_TYPE_AMZ,
                    DesignImportRequestDetails::COL_MBA_IDS,
                    DesignImportRequestDetails::COL_COLOR_CATALOG_ID,
                    DesignImportRequestDetails::COL_RULE_ID,
                    DesignImportRequestDetails::COL_STATUS,
                    DesignImportRequestDetails::COL_CREATED_BY
                ]
            );
            if ($response) {
                $pushingService->post($distributionId, DistributionStates::DISTRIBUTION_STATES_COMPLETED);
            }
        } catch (Exception $e) {
            if (!empty($e->getMessage())) {
                $pushingService->post($distributionId, DistributionStates::DISTRIBUTION_STATES_FAILED, $e->getMessage());
                DesignImportRequestDetails::where(
                    [
                        DesignImportRequestDetails::COL_DESIGN_ID => $requestId
                    ]
                )->update(
                    [
                        'status'    => DesignImportRequestDetails::STATUS_FAILED,
                        'logs'      => $e->getMessage()
                    ]
                );

                DesignImportRequests::where(
                    [
                        DesignImportRequests::COL_ID => $requestId
                    ]
                )->update(
                    [
                        'finished_at'   => Carbon::now(),
                        'status'        => DesignImportRequests::STATUS_READ_FAILED,
                        'logs'          => $e->getMessage()
                    ]
                );
            }
        }
    }
}
