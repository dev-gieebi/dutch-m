<?php

use Illuminate\Support\Facades\Broadcast;

/*
| Canaux privés du back-office (autorisation : JWT administrateur, voir AdminAuth).
| Les conversations client utilisent le canal public « chat.{jeton secret} ».
*/

Broadcast::channel('admins', fn ($admin) => true);

Broadcast::channel('admin.{id}', fn ($admin, $id) => (int) $admin->id === (int) $id);
