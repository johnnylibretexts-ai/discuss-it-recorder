<?php
require '/var/www/tmp/vendor/autoload.php';
$app = require '/var/www/tmp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$finished = false;
register_shutdown_function(function () use (&$finished) { if (!$finished) exit(1); });
$directory = sys_get_temp_dir().'/discuss-it-media-test-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
// Logs go nowhere; the checks below inspect them through log events instead.
config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'filesystems.disks.s3' => ['driver' => 'local', 'root' => $directory],
    'logging.channels.discuss-it-test' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class], 'logging.default' => 'discuss-it-test']);
require '/var/www/tmp/database/migrations/2026_09_09_000001_create_discuss_it_tables.php';
(new CreateDiscussItTables())->up();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
function runMedia(array $args) { $p = new Process($args); $p->setTimeout(30); $p->mustRun(); return $p->getOutput(); }
function processMedia($directory, $kind, callable $create) {
    $id = (string) Illuminate\Support\Str::uuid();
    Storage::disk('s3')->makeDirectory($id);
    $create($directory.'/'.$id.'/input');
    DB::table('discuss_it_media')->insert(['id' => $id, 'assignment_id' => 1, 'question_id' => 1, 'user_id' => 1, 'object_key' => $id.'/input', 'kind' => $kind, 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
    (new App\Services\DiscussItMediaProcessor())->process(DB::table('discuss_it_media')->where('id', $id)->first());
    return DB::table('discuss_it_media')->where('id', $id)->first();
}
function sine($seconds) {
    return function ($file) use ($seconds) { runMedia(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', (string) $seconds, '-c:a', 'aac', '-f', 'mp4', $file]); };
}
$logs = [];
Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logs) { $logs[] = $event; });
function loggedFor($logs, $media) {
    foreach ($logs as $event) if (($event->context['media_id'] ?? null) === $media->id) return $event;
    return null;
}
$inputs = [
    'video' => function ($file) { runMedia(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=purple:s=320x240:r=15', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000',
        '-t', '1.5', '-threads', '1', '-c:a', 'aac', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $file]); },
    'audio' => sine(1.5),
    'invalid' => function ($file) { file_put_contents($file, "#EXTM3U\nfile:///etc/passwd\n"); },
];
foreach ($inputs as $kind => $create) {
    $result = processMedia($directory, $kind === 'audio' ? 'audio' : 'video', $create);
    if ($kind === 'invalid') {
        if ($result->status !== 'failed') throw new RuntimeException('Playlist input was not rejected');
        $event = loggedFor($logs, $result);
        if (!$event || $event->level !== 'notice' || strpos($result->error, 'under 5 minutes') === false) throw new RuntimeException('Rejected media is logged as a notice and explained to the student');
        // json_encode escapes '/' by default, which would hide any path from strpos.
        $logged = json_encode($event->context, JSON_UNESCAPED_SLASHES);
        foreach ([$directory, 'discuss-input-', 'discuss-output-'] as $mediaPath) {
            if (strpos($logged, $mediaPath) !== false) throw new RuntimeException('Processing logs must not include media paths');
        }
        echo "PASS media rejection logged without media paths\n";
    } else {
        if ($result->status !== 'ready' || $result->duration_ms < 1400 || !Storage::disk('s3')->exists($result->output_key)) throw new RuntimeException($kind.' processing failed');
        if (Storage::disk('s3')->exists($result->object_key)) throw new RuntimeException('Temporary upload retained after success');
    }
    echo 'PASS media '.$kind.' '.$result->status."\n";
}
$boundary = processMedia($directory, 'audio', sine(300.8));
if ($boundary->status !== 'ready') throw new RuntimeException('A 300.8 s clip is within the limit and must not fail after transcoding');
$long = processMedia($directory, 'audio', sine(303));
if ($long->status !== 'failed' || strpos($long->error, 'under 5 minutes') === false) throw new RuntimeException('A clip over the limit is rejected as a media problem');
echo "PASS media duration limit is the same before and after transcoding\n";
// A missing ffprobe is a server problem: tell the student so, and log it as an error.
$path = getenv('PATH');
$setPath = function ($value) { putenv('PATH='.$value); $_ENV['PATH'] = $_SERVER['PATH'] = $value; };
$broken = processMedia($directory, 'audio', function ($file) use ($setPath) { call_user_func(sine(1.5), $file); $setPath('/nonexistent'); });
$setPath($path);
$event = loggedFor($logs, $broken);
if ($broken->status !== 'failed' || strpos($broken->error, 'server problem') === false) throw new RuntimeException('A missing ffprobe is reported to the student as a server problem');
if (!$event || $event->level !== 'error' || ($event->context['exit_code'] ?? null) !== 127) throw new RuntimeException('Server-side processing failures are logged as errors with the exit code');
echo "PASS media server failure logged and reported as a server problem\n";
// An encoder failure after the input was read may be the server's (ffmpeg 8 exits 8 when
// libx264 is missing), so it is also reported as a server problem and logged as an error.
$bin = $directory.'/bin';
mkdir($bin, 0700);
symlink((new Symfony\Component\Process\ExecutableFinder())->find('ffprobe'), $bin.'/ffprobe');
file_put_contents($bin.'/ffmpeg', "#!/bin/sh\necho 'Unknown encoder' >&2\nexit 8\n");
chmod($bin.'/ffmpeg', 0700);
$encoder = processMedia($directory, 'audio', function ($file) use ($setPath, $bin) { call_user_func(sine(1.5), $file); $setPath($bin); });
$setPath($path);
$event = loggedFor($logs, $encoder);
if ($encoder->status !== 'failed' || strpos($encoder->error, 'server problem') === false) throw new RuntimeException('An encoder failure is reported to the student as a server problem');
if (!$event || $event->level !== 'error' || ($event->context['exit_code'] ?? null) !== 8) throw new RuntimeException('An encoder failure is logged as an error with the exit code');
echo "PASS media encoder failure logged and reported as a server problem\n";
// A logger that throws (say, on a log file another user created) must not leave the media
// processing or stop the rest of the batch.
$failLogging = false;
Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class, function () use (&$failLogging) { if ($failLogging) throw new UnexpectedValueException('The log file is not writable'); });
$failLogging = true;
try { $unlogged = processMedia($directory, 'audio', function ($file) { file_put_contents($file, 'not media'); }); } finally { $failLogging = false; }
if ($unlogged->status !== 'failed' || strpos($unlogged->error, 'under 5 minutes') === false) throw new RuntimeException('A media failure is recorded even when logging fails');
echo "PASS media failure recorded even when logging fails\n";
// Once the recording is stored and marked ready, failing to remove the upload must not fail it.
$local = Storage::disk('s3');
Storage::set('s3', new class($local->getDriver()) extends Illuminate\Filesystem\FilesystemAdapter {
    public function delete($paths) { throw new RuntimeException('Injected storage outage'); }
});
try { $kept = processMedia($directory, 'audio', sine(1.5)); } finally { Storage::set('s3', $local); }
if ($kept->status !== 'ready' || $kept->error !== null || !Storage::disk('s3')->exists($kept->output_key)) throw new RuntimeException('A recording stays ready when its upload cannot be removed');
echo "PASS media stays ready when the upload cannot be removed\n";
$finished = true;
