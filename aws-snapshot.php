<?php
require('./vendor/autoload.php');

$dotenv = new \Dotenv\Dotenv(__DIR__);
$dotenv->load();

$logger = new \Monolog\Logger('event-logs');
$logger->pushHandler(new \Monolog\Handler\StreamHandler(__DIR__ . '/event-log.log'));
$today = \Carbon\Carbon::now();

$logger->info('Logging for date: ' . $today->toDateString());

/**
 * Setting access key variables
 */
$awsAccessKeyId = getenv('AWS_ACCESS_KEY_ID');
$awsAccessKeySecret = getenv('AWS_ACCESS_KEY_SECRET');

putenv("AWS_ACCESS_KEY_ID=$awsAccessKeyId");
putenv("AWS_SECRET_ACCESS_KEY=$awsAccessKeySecret");

$aws = new Aws\Ec2\Ec2Client([
    'region' => getenv('AWS_EC2_ClIENT_REGION'), //region
    'version' => getenv('AWS_EC2_CLIENT_VERSION'), //version
]);

try {

    /**
     * Fetching all the snapshots for the given volume
     */
    $result = $aws->describeSnapshots([
        'Filters' => [
            [
                'Name' => 'status',
                'Values' => ['completed']
            ], [
                'Name' => 'volume-id',
                'Values' => [getenv('VOLUME_ID')]
            ]
        ]
    ]);


    /**
     * Converting the StartTime of all snapshots to Carbon DateTime instances
     */
    $snapshots = collect($result->get('Snapshots'))->transform(function ($snapshot) {
        $snapshot['StartTime'] = \Carbon\Carbon::parse($snapshot['StartTime']);
        return $snapshot;
    });

    /**
     * The timestamp of newest snapshot created
     *
     * @var \Carbon\Carbon $maxDate
     */
    $maxDate = $snapshots->max('StartTime');
    $maxDate->setTime(0, 0, 0);

    /**
     * Finding out the date of wednesday of last week from current date
     */
    if ($maxDate->dayOfWeek >= \Carbon\Carbon::THURSDAY) {
        $lastWednesdayFromMaxDate = $maxDate->copy()->subDays($maxDate->dayOfWeek - \Carbon\Carbon::WEDNESDAY);
    } else {
        $lastWednesdayFromMaxDate = $maxDate->copy()->subDays(7);
        $lastWednesdayFromMaxDate = $lastWednesdayFromMaxDate->addDays(\Carbon\Carbon::WEDNESDAY - $lastWednesdayFromMaxDate->dayOfWeek);
    }

    $previous3Wednesdays = [
        $lastWednesdayFromMaxDate,
        $lastWednesdayFromMaxDate->copy()->subDays(7),
        $lastWednesdayFromMaxDate->copy()->subDays(14),
        $lastWednesdayFromMaxDate->copy()->subDays(21),
    ];

    $logger->info('Dates not to delete :' . $maxDate->toDateString() . ',' . implode(',', $previous3Wednesdays));

    /**
     * Filtering out the latest and last weeks wednesday snapshots from the list
     */
    $toDeleteSnapshots = $snapshots->filter(function ($snapshot) use ($maxDate, $lastWednesdayFromMaxDate, $previous3Wednesdays) {
        /**
         * @var \Carbon\Carbon $startTime
         */
        $startTime = $snapshot['StartTime'];
        $startTime->setTime(0, 0, 0);

        if ($startTime->eq($maxDate)) {
            return false;
        }

        foreach ($previous3Wednesdays as $previous3Wednesday) {
            if ($startTime->eq($previous3Wednesday)) {
                return false;
            }
        }
        return true;
    });

    $logger->info('Dates to delete are: ' . $toDeleteSnapshots->pluck('StartTime')->transform(function ($item) {
            return $item->toDateString();
        })->implode(','));


    /**
     * Deleting the snapshots one by one
     *
     */
    $toDeleteSnapshots->each(function ($snapshot) use ($aws, $logger) {
        $aws->deleteSnapshot([
            'DryRun' => getenv('DRY_RUN') == true ? true : false,
            'SnapshotId' => $snapshot['SnapshotId']
        ]);
        $logger->info('Deleting Snapshot:' . $snapshot['SnapshotId']);
    });

    $logger->info('End Logging for Date ' . $today->toDateString());

} catch (\Exception $e) {

    $logger->error('Error encountered during execution');
    $logger->error($e);
}



