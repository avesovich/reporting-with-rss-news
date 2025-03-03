<?php

namespace App\Http\Controllers\Article;

use Inertia\Inertia;
use App\Models\Article;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Mews\Purifier\Facades\Purifier;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;


class ArticleController extends Controller
{

    public function index(Request $request, $status)
    {
        $validStatuses = ['Review', 'Updated', 'Revision', 'Evaluated', 'Approved'];
    
        // ✅ Ensure the provided status is valid
        if (!in_array($status, $validStatuses)) {
            abort(404, 'Invalid status.');
        }
    
        $user = auth()->user();
    
        // ✅ Query articles based on status
        $articlesQuery = Article::where('approval_status', $status)
            ->orderBy(($status === 'Review') ? 'created_at' : 'updated_at', 'desc');
    
        // ✅ Editors can only see their own articles
        if ($user->hasRole('editor')) {
            $articlesQuery->where('user_id', $user->id);
        }
    
        return Inertia::render("Status/{$status}", [
            'articles' => $articlesQuery->paginate(10),
            'userRoles' => $user->roles->pluck('name')->toArray(),
        ]);
    }

    public function show(Request $request, $status, $id)
    {
        $validStatuses = ['Review', 'Updated', 'Revision', 'Evaluated', 'Approved'];
    
        if (!in_array($status, $validStatuses)) {
            abort(404, 'Invalid status.');
        }
    
        $article = Article::findOrFail($id);
        $user = auth()->user();
    
        if (strtolower($article->approval_status) !== strtolower($status)) {
            abort(404, 'Article not found or does not match the requested status.');
        }
    
        if (!$user->hasRole(['editor', 'executive', 'administrator']) && $article->user_id !== $user->id) {
            return redirect()->back()->withErrors(['error' => 'Unauthorized']);
        }
    
        $userRoles = $user->roles->pluck('name')->toArray();
        $page = $request->query('page', 1);
    
        $commentsQuery = $article->comments()->with('user')->latest();
        if ($user->hasRole('executive')) {
            $commentsQuery->where('user_id', $user->id);
        }
        $comments = $commentsQuery->paginate(5);

        $imagePaths = json_decode($article->image_path, true) ?? [];

        return Inertia::render("Status/View", [
            'article' => $article,
            'comments' => [
                'data' => $comments->items(),
                'currentPage' => $comments->currentPage(),
                'totalPages' => $comments->lastPage(),
                'totalItems' => $comments->total(),
                'pageSize' => $comments->perPage(),
            ],
            'userRoles' => $userRoles,
            'imagePaths' => $imagePaths,
        ]);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'publication_date' => 'required|date',
            'type_of_report' => 'required|string|in:Breach,Data Leak,Malware Information,Threat Actors Updates,Cyber Awareness,Vulnerability Exploitation,Phishing,Ransomware,Social Engineering,Illegal Access',
            'url' => 'required|url|max:2083',
            'detailed_summary' => 'required|string',
            'analysis' => 'required|string',
            'recommendation' => 'required|string',
            'image_path' => 'nullable|array',
        ]);
    
        $validatedData['title'] = Purifier::clean($validatedData['title'], 'default');
        $validatedData['detailed_summary'] = Purifier::clean($validatedData['detailed_summary'], 'default');
        $validatedData['analysis'] = Purifier::clean($validatedData['analysis'], 'default');
        $validatedData['recommendation'] = Purifier::clean($validatedData['recommendation'], 'default');
    
        $user = auth()->user();
    
        $article = new Article([
            'title' => $validatedData['title'],
            'publication_date' => $validatedData['publication_date'],
            'type_of_report' => $validatedData['type_of_report'],
            'url' => $validatedData['url'],
            'detailed_summary' => $validatedData['detailed_summary'],
            'analysis' => $validatedData['analysis'],
            'recommendation' => $validatedData['recommendation'],
            'approval_status' => Article::STATUS_REVIEW,
            'image_path' => isset($validatedData['image_path']) ? json_encode($validatedData['image_path']) : null,
        ]);
    
        $article->user_id = $user->id;
        $article->editor_name = $user->name;
        $article->posted_date = now()->toDateString();
        $article->time_posted = now()->toTimeString();
        $article->save();
    
        Cache::forget('total_reports');
        Cache::forget('reports_this_week');
        Cache::forget('reports_this_month');
    
        return redirect()->route('status.index', ['status' => 'Review'])->with([
            'successMessage' => 'Article successfully submitted.',
        ]);
    }
     
    public function setApprovalStatus(Request $request, $id)
    {
        $article = Article::findOrFail($id);
        $user = auth()->user();
    
        // ✅ Restrict to Administrators and Executives
        if (!$user->hasAnyRole(['administrator', 'executive'])) {
            abort(403, 'Unauthorized');
        }
    
        // ✅ Validate status input
        $validatedData = $request->validate([
            'status' => 'required|string|in:approved,disapproved',
        ]);
    
        // ✅ Define allowed status transitions
        $allowedStatuses = [
            'approved' => [
                'administrator' => Article::STATUS_EVALUATED,
                'executive' => Article::STATUS_APPROVED,
            ],
            'disapproved' => [
                'administrator' => Article::STATUS_REVISION,
                'executive' => Article::STATUS_EVALUATED, // ✅ Executives redirect to Evaluated instead of Revision
            ],
        ];
    
        // ✅ Ensure only allowed statuses are assigned based on role
        $role = $user->hasRole('executive') ? 'executive' : 'administrator';
        $newStatus = $allowedStatuses[$validatedData['status']][$role] ?? null;
    
        if (!$newStatus) {
            abort(400, 'Invalid approval status.');
        }
    
        // ✅ Update the article safely
        $article->update(['approval_status' => $newStatus]);
    
        // ✅ Define correct redirect route
        $redirectRoute = route('status.index', ['status' => $newStatus]); // ✅ Use correct route format
    
        return redirect($redirectRoute)->with([
            'successMessage' => match ($newStatus) {
                Article::STATUS_APPROVED => 'Article successfully approved.',
                Article::STATUS_EVALUATED => 'Article successfully evaluated.',
                Article::STATUS_REVISION => 'Article sent for revision.',
            },
        ]);
    }
    
    
    

    public function update(Request $request, $id)
    {
        $user = auth()->user();
    
        // ✅ Fetch article only if it belongs to the user AND is in 'Revision' status
        $article = Article::where('id', $id)
            ->where('user_id', $user->id)
            ->where('approval_status', 'Revision') // 🚀 This prevents updates on non-Revision articles
            ->first();
    
        // ❌ If article does not exist (wrong ID, wrong owner, or not "Revision"), deny access
        if (!$article) {
            abort(403, 'Unauthorized or invalid article status.');
        }
    
        // ✅ Validate input fields
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'type_of_report' => 'required|string|in:Breach,Data Leak,Malware Information,Threat Actors Updates,Cyber Awareness,Vulnerability Exploitation,Phishing,Ransomware,Social Engineering,Illegal Access',
            'publication_date' => 'required|date',
            'url' => 'required|url|max:2083',
            'detailed_summary' => 'required|string',
            'analysis' => 'required|string',
            'recommendation' => 'required|string',
        ]);
    
        // ✅ Use Purifier to prevent XSS
        $validatedData['title'] = Purifier::clean($validatedData['title'], 'default');
        $validatedData['detailed_summary'] = Purifier::clean($validatedData['detailed_summary'], 'default');
        $validatedData['analysis'] = Purifier::clean($validatedData['analysis'], 'default');
        $validatedData['recommendation'] = Purifier::clean($validatedData['recommendation'], 'default');
    
        // ✅ Update the article and keep status as "Updated"
        $article->update([
            'title' => $validatedData['title'],
            'type_of_report' => $validatedData['type_of_report'],
            'publication_date' => $validatedData['publication_date'],
            'url' => $validatedData['url'],
            'detailed_summary' => $validatedData['detailed_summary'],
            'analysis' => $validatedData['analysis'],
            'recommendation' => $validatedData['recommendation'],
            'approval_status' => 'Updated', // Keep status as "Updated"
            'updated_at' => now(),
        ]);
    
        // ✅ Redirect with success message
        return redirect()->route('status.index', ['status' => 'Updated'])->with([
            'successMessage' => 'Article successfully updated.',
        ]);  
    }
    
    public function edit($id)
    {
        $user = auth()->user();
    
        if (!$user->hasRole('editor')) {
            abort(403, 'Unauthorized: Only editors can edit articles.');
        }
    
        $article = Article::where('id', $id)
            ->where('user_id', $user->id)
            ->where('approval_status', 'Revision')
            ->first();
    
        if (!$article) {
            abort(403, 'Unauthorized');
        }
    
        // ✅ Fetch related comments
        $comments = $article->comments()->with('user')->latest()->paginate(5);
    
        return Inertia::render('Forms/UpdateReport', [
            'article' => $article,
            'comments' => [
                'data' => $comments->items() ?? [],
                'currentPage' => $comments->currentPage(),
                'totalPages' => $comments->lastPage(),
                'totalItems' => $comments->total(),
                'pageSize' => $comments->perPage(),
            ],
            'typeOfReportOptions' => [
                'Breach',
                'Data Leak',
                'Malware Information',
                'Threat Actors Updates',
                'Cyber Awareness',
                'Vulnerability Exploitation',
                'Phishing',
                'Ransomware',
                'Social Engineering',
                'Illegal Access',
            ],
        ]);
    }

    public function uploadImages(Request $request)
    {
        $user = auth()->user();
        if (!$user->hasRole('editor')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $request->validate([
            'images' => 'required|array|max:10',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);
    
        $paths = [];
        foreach ($request->file('images') as $file) {
            $hashedName = Str::random(40) . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('articles', $hashedName);
            $paths[] = $filePath;
        }
    
        return response()->json(['paths' => $paths]);
    }
    

    public function getImage($filename)
    {
        $user = auth()->user();
        if (!$user->hasAnyRole(['administrator', 'editor', 'executive'])) {
            abort(403, 'Unauthorized');
        }

        $filename = basename($filename);
        $path = storage_path('app/private/articles/' . $filename);

        if (!File::exists($path)) {
            abort(404, 'File not found.');
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mimeType = File::mimeType($path);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            abort(403, 'Invalid file type.');
        }

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private static function escapeCsvValue($value)
    {
        if (strpos($value, '=') === 0 || strpos($value, '+') === 0 || strpos($value, '-') === 0 || strpos($value, '@') === 0) {
            return "'" . $value;
        }
        return $value;
    }

    public function exportCsv($status)
    {
        $validStatuses = ['Review', 'Updated', 'Revision', 'Evaluated', 'Approved'];
    
        if (!in_array($status, $validStatuses)) {
            abort(404, 'Invalid status.');
        }
    
        $user = auth()->user();
    
        if (!$user->hasAnyRole(['administrator', 'executive', 'editor'])) {
            abort(403, 'Unauthorized');
        }
    
        $articlesQuery = Article::where('approval_status', $status);
    
        if ($user->hasRole('editor')) {
            $articlesQuery->where('user_id', $user->id);
        }
    
        $articles = $articlesQuery->get();
    
        $filename = 'articles_' . strtolower($status) . '.csv';
    
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    
        $callback = function () use ($articles) {
            $file = fopen('php://output', 'w');
    
            fputcsv($file, ['ID', 'Title', 'Publication Date', 'Type of Report', 'URL', 'Editor Name', 'Detailed Summary', 
            'Analysis', 'Recommendation', 'Approval Status', 'Created At', 'Updated At']);
    
            foreach ($articles as $article) {
                fputcsv($file, [
                    $article->id,
                    self::escapeCsvValue($article->title),
                    $article->publication_date,
                    self::escapeCsvValue($article->type_of_report),
                    self::escapeCsvValue($article->url),
                    self::escapeCsvValue($article->editor_name),
                    self::escapeCsvValue($article->detailed_summary),
                    self::escapeCsvValue($article->analysis),
                    self::escapeCsvValue($article->recommendation),
                    $article->approval_status,
                    $article->created_at,
                    $article->updated_at,
                ]);
            }
    
            fclose($file);
        };
    
        return new StreamedResponse($callback, 200, $headers);
    }
    
}