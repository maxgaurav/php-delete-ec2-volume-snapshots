<?php
require('./vendor/autoload.php');

$dotenv = new \Dotenv\Dotenv(__DIR__);
$dotenv->load();

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
$maxDate->setTime(0,0,0);

/**
 * Finding out the date of wednesday of last week from current date
 */
$lastWednesdayFromMaxDate = $maxDate->copy()->subDays(7);
$lastWednesdayFromMaxDate = $lastWednesdayFromMaxDate->addDays(\Carbon\Carbon::WEDNESDAY - $lastWednesdayFromMaxDate->dayOfWeek);

/**
 * Filtering out the latest and last weeks wednesday snapshots from the list
 */
$toDeleteSnapshots = $snapshots->filter(function ($snapshot) use ($maxDate, $lastWednesdayFromMaxDate) {
    /**
     * @var \Carbon\Carbon $startTime
     */
    $startTime = $snapshot['StartTime'];
    $startTime->setTime(0,0,0);
    return $startTime->notEqualTo($maxDate) && $startTime->notEqualTo($lastWednesdayFromMaxDate);
});


/**
 * Deleting the snapshots one by one
 *
 */
$toDeleteSnapshots->each(function($snapshot) use($aws){
    $aws->deleteSnapshot([
        'DryRun' => getenv('DRY_RUN'),
        'SnapshotId' => $snapshot['SnapshotId']
    ]);
});


