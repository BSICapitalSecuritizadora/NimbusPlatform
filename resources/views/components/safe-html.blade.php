@props(['html' => null])

{{-- Renders admin-authored rich text unescaped, after sanitizing it. --}}
{!! \App\Services\Security\RichTextSanitizer::sanitize($html) !!}
