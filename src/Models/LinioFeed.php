<?php

namespace Astroselling\LinioSdk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Linio\SellerCenter\Model\Feed\Feed;

class LinioFeed extends Model
{
    use SoftDeletes;

    protected const STATUS_QUEUED = 'Queued'; // Feed was successfully added to the queue and is awaiting processing
    protected const STATUS_PROCESSING = 'Processing'; // Feed is currently being processed by the server
    protected const STATUS_CANCELED = 'Canceled'; // Feed was canceled by the seller
    protected const STATUS_FINISHED = 'Finished'; // Feed has finished processing
    protected const STATUS_ERROR = 'Error'; // Feed has finished with error(s)

    protected $casts = [
        'errors' => 'array',
        'warnings' => 'array',
        'failure_reports' => 'array',
        'creation_date' => 'datetime',
        'updated_date' => 'datetime',
    ];

    public function isCompleted() : bool
    {
        return in_array($this->status, [self::STATUS_CANCELED, self::STATUS_FINISHED, self::STATUS_ERROR]);
    }

    public static function saveFromLinio(Feed $feed) : self
    {
        $newFeed = self::where('feed_id', $feed->getId())->firstOrNew();
        $newFeed->feed_id = $feed->getId();
        $newFeed->status = $feed->getStatus();
        $newFeed->source = $feed->getSource();
        $newFeed->action = $feed->getAction();
        $newFeed->creation_date = $feed->getCreationDate();
        $newFeed->updated_date = $feed->getUpdatedDate();
        $newFeed->total_records = $feed->getTotalRecords();
        $newFeed->processed_records = $feed->getProcessedRecords();
        $newFeed->failed_records = $feed->getFailedRecords();
        $newFeed->errors = $feed->getErrors();
        $newFeed->warnings = $feed->getWarnings();
        $newFeed->failure_reports = $feed->getFailureReports();
        $newFeed->save();
        return $newFeed;
    }
}
