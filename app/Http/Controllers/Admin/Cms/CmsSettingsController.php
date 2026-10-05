<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsSettingsRequest;
use App\Models\Cms\CmsSetting;
use App\Support\Cms\CmsRegistry;

/** Contact details, footer text and social links. Public values only; no secrets. */
class CmsSettingsController extends Controller
{
    public function edit()
    {
        $stored = CmsSetting::pluck('value', 'key');
        $groups = [];
        foreach (CmsRegistry::settings() as $key => $def) {
            $groups[$def['group']][$key] = $def + ['value' => $stored[$key] ?? $def['default']];
        }

        return view('admin.cms.settings.edit', compact('groups'));
    }

    public function update(CmsSettingsRequest $request)
    {
        // Only whitelisted keys are ever written; unknown input keys are dropped by validated().
        foreach ($request->validatedSettings() as $key => $value) {
            CmsSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return redirect()->route('admin.cms.settings.edit')->with('success', 'Site settings saved.');
    }
}
