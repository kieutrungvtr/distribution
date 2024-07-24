<?php

namespace App\Libs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleDrive
{
    const FOLDER                = '/folders/';
    const FOLDER_URL            = 'https://drive.google.com/drive/folders/';
    const IMAGE_URL             = 'https://drive.google.com/uc?id=';
    const READABLE_IMAGE_URL    = 'https://drive.google.com/file/d/%s/view';
    const DOWNLOAD_IMAGE_URL    = 'https://drive.google.com/uc?export=download&id=';

    const TYPE_READABLE         = 'read';
    const TYPE_DOWNLOAD         = 'download';

    public static function listFiles($folder_id, $order_by = 'modifiedTime,name_natural', $query = '')
    {
        try {
            $folders = [];
            $pageToken = null;
            $q = "'$folder_id' in parents and trashed=false";

            if (!empty($query)) {
                $q .= " and $query";
            }

            do {
                $response = Storage::disk('google')->files->listFiles([
                    'orderBy'   => $order_by,
                    'pageSize'  => 1000,
                    'pageToken' => $pageToken,
                    'q'         => $q,
                    'spaces'    => 'drive',
                    'fields'    => 'nextPageToken, files(id, name)',
                    'includeItemsFromAllDrives' => true,
                    'supportsAllDrives' => true
                ]);

                foreach ($response->files as $file) {
                    $folders[] = [
                        'id'    => $file->id,
                        'name'  => $file->name
                    ];
                }
                $pageToken = $response->pageToken;
            } while ($pageToken != null);

            return $folders;
        } catch (\Exception $e) {
            Log::error(get_class() . '; Error: ' . $e->getMessage());

            return [];
        }
    }

    public static function getFile($file_id)
    {
        try {
            return Storage::disk('google')->files->get($file_id, ['alt' => 'media']);
        } catch (\Exception $e) {
            Log::error(get_class() . '; Error: ' . $e->getMessage());
            return '';
        }
    }

    public static function getUrl($file_id, $type = null)
    {
        switch ($type) {
            case self::TYPE_READABLE:
                return sprintf(self::READABLE_IMAGE_URL, $file_id);
            case self::TYPE_DOWNLOAD:
                return self::DOWNLOAD_IMAGE_URL . $file_id;
            default:
                return self::IMAGE_URL . $file_id;
        }
    }
}