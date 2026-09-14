<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Download an attachment: task evidence, a project file, an expense receipt.
 *
 * Uploads live on the private disk, so they can only be reached through here, and
 * only by someone allowed to view the record they hang off.
 */
class MediaDownloadController extends Controller
{
    public function media(Request $request, Media $media): StreamedResponse
    {
        $owner = $media->mediable;

        if ($owner === null) {
            throw new NotFoundHttpException;
        }

        // Permission follows the parent record, so project scoping applies here too.
        Gate::forUser($request->user())->authorize('view', $owner);

        return $this->stream($media->disk, (string) $media->path, (string) $media->original_name);
    }

    public function receipt(Request $request, Expense $expense): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('view', $expense);

        if (blank($expense->receipt_path)) {
            throw new NotFoundHttpException;
        }

        return $this->stream(
            'local',
            (string) $expense->receipt_path,
            (string) ($expense->receipt_original_name ?: 'receipt'),
        );
    }

    private function stream(string $disk, string $path, string $name): StreamedResponse
    {
        $storage = Storage::disk($disk);

        if ($path === '' || ! $storage->exists($path)) {
            throw new NotFoundHttpException;
        }

        // Always a download, always an opaque type: an uploaded .html or .svg must
        // never get to run script on this origin.
        return $storage->download($path, $name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
