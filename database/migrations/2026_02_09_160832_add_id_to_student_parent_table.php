<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdToStudentParentTable extends Migration
{
    public function up()
    {
        // Supprimer d'abord la clé primaire existante
        Schema::table('student_parent', function (Blueprint $table) {
            // D'abord, supprimer la clé primaire composite
            $table->dropPrimary(['student_id', 'parent_id']);
        });

        // Ensuite ajouter la colonne id
        Schema::table('student_parent', function (Blueprint $table) {
            $table->id()->first();
            
            // Ajouter une contrainte d'unicité pour éviter les doublons
            $table->unique(['student_id', 'parent_id', 'deleted_at'], 'student_parent_unique');
        });
    }
    
    public function down()
    {
        Schema::table('student_parent', function (Blueprint $table) {
            // Supprimer l'unicité
            $table->dropUnique('student_parent_unique');
            
            // Supprimer la colonne id
            $table->dropColumn('id');
            
            // Rétablir la clé primaire composite
            $table->primary(['student_id', 'parent_id']);
        });
    }
}