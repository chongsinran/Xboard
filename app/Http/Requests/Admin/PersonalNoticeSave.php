<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PersonalNoticeSave extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'content' => 'required|string',
            'content_format' => 'nullable|in:markdown,plain',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array',
            'show' => 'nullable|boolean',
            'recipient_ids' => 'nullable|array',
            'recipient_ids.*' => 'integer|min:1',
            'recipient_emails' => 'nullable|array',
            'recipient_emails.*' => 'email',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'content_format.in' => '内容格式不正确',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确',
            'recipient_ids.array' => '用户ID列表格式不正确',
            'recipient_emails.array' => '用户邮箱列表格式不正确',
            'recipient_emails.*.email' => '用户邮箱格式不正确',
        ];
    }
}
