<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateYouTubeMusicConnectionRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cookie' => [
                'required',
                'string',
                'max:8192',
                'regex:/__Secure-3PAPISID=/',
                'regex:/\bSAPISID=/',
                'regex:/\bSID=/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cookie.regex' => 'This does not look like a YouTube Music cookie. It must contain __Secure-3PAPISID, SAPISID and SID. Copy the whole value of the "cookie" request header.',
        ];
    }
}
