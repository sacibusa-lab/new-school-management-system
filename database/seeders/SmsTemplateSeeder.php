<?php

namespace Database\Seeders;

use App\Models\SmsTemplate;
use App\Support\SmsTemplateKey;
use Illuminate\Database\Seeder;

class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SmsTemplateKey::defaults() as $key => $definition) {
            $template = SmsTemplate::query()->firstOrNew(['key' => $key]);

            $template->name = $definition['name'];
            $template->description = $definition['description'];

            // Never overwrite wording the school has edited.
            if (! $template->exists || blank($template->body)) {
                $template->body = $definition['body'];
            }

            $template->is_active ??= true;
            $template->save();
        }
    }
}
