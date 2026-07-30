<?php

namespace App\Http\Requests\Site;

use App\Models\ContactMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', Rule::in(array_keys(ContactMessage::SUBJECT_OPTIONS))],
            'message' => ['required', 'string', 'max:5000'],
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome completo.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'subject.required' => 'Selecione o assunto.',
            'subject.in' => 'Selecione um assunto válido.',
            'message.required' => 'Descreva sua demanda.',
            'message.max' => 'A mensagem não pode ultrapassar 5.000 caracteres.',
            'website.prohibited' => 'Não foi possível enviar sua mensagem.',
        ];
    }
}
