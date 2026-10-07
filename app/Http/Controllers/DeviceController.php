<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::query()->orderBy('name')->paginate(15);

        return view('devices.index', compact('devices'));
    }

    public function create()
    {
        return view('devices.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        Device::create($validated);

        return redirect()->route('devices.index')->with('success', 'Appareil cree.');
    }

    public function edit(Device $device)
    {
        return view('devices.edit', compact('device'));
    }

    public function update(Request $request, Device $device)
    {
        $validated = $this->validated($request, $device);

        $device->update($validated);

        return redirect()->route('devices.index')->with('success', 'Appareil mis a jour.');
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return redirect()->route('devices.index')->with('success', 'Appareil supprime.');
    }

    private function validated(Request $request, ?Device $device = null): array
    {
        $uniqueCode = 'unique:devices,code' . ($device ? ',' . $device->id : '');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', $uniqueCode],
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['status'] = $validated['is_active'] ? Device::STATUS_ACTIVE : Device::STATUS_INACTIVE;

        return $validated;
    }
}
