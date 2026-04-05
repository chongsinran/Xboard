<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Plugin\HookManager;

class KnowledgeResource extends JsonResource
{
  /**
   * Transform the resource into an array.
   *
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    $data = [
      'id' => $this['id'],
      'language' => $this['language'] ?? null,
      'category' => $this['category'],
      'title' => $this['title'],
      'body' => $this->when(isset($this['body']), $this['body']),
      'show' => $this['show'] ?? 1,
      'created_at' => $this['created_at'] ?? null,
      'updated_at' => $this['updated_at'],
    ];

    return HookManager::filter('user.knowledge.resource', $data, $request, $this);
  }
}
