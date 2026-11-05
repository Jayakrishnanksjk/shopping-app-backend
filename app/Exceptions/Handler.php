<?php

namespace App\Exceptions;

use Exception;
use Throwable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use App\GraphQL\Exceptions\ExceptionHandler as GraphQLExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->renderable(function (Exception $exception, $request) {

            if ($exception instanceof NotFoundHttpException) {

                if ($request->hasHeader("Accept-Language")) {
                    // Parse Accept-Language header properly
                    $locale = $this->parseAcceptLanguage($request->header("Accept-Language"));
                    if ($locale) {
                        app()->setLocale($locale);
                    }
                }

                throw new GraphQLExceptionHandler($exception->getMessage(), 400);
            }
        });

        $this->renderable(function (GraphQLExceptionHandler $e, $request) {
            $code = $e->getCode();
            $statusCode = (is_int($code) && $code >= 100 && $code <= 599) ? $code : 500;
            return response()->json([
                'message' => $e->getMessage(),
                'success' => false,
            ], $statusCode);
        });
    }

    /**
     * Parse Accept-Language header and extract the primary locale
     *
     * @param string $header
     * @return string|null
     */
private function parseAcceptLanguage(?string $header): ?string
{
    if (! $header || $header === '*') {
        return config('app.fallback_locale', 'en');
    }

    // Extract the first locale before comma
    $locales = explode(',', $header);

    if (empty($locales)) {
        return config('app.fallback_locale', 'en');
    }

    $primaryLocale = trim(explode(';', $locales[0])[0]);

    // Reject wildcard or invalid values
    if ($primaryLocale === '*' || strlen($primaryLocale) < 2) {
        return config('app.fallback_locale', 'en');
    }

    $locale = strtolower(substr($primaryLocale, 0, 2));

    // Allowlist
    $allowed = ['en', 'fr', 'ar', 'hi'];

    if (! in_array($locale, $allowed, true)) {
        return config('app.fallback_locale', 'en');
    }

    return $locale;
}

}
