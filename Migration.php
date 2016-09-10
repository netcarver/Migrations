<?php

abstract class Migration extends Wire{

	public static $description;

	abstract public function update();

	abstract public function downgrade();


	/**
	 * Cycle over a group of pages without running into memory exhaustion
	 *
	 * @param string   $selector
	 * @param callable $callback
	 * @return int     $num
	 */
	protected function eachPageUncache($selector, callable $callback)
	{
		$num = 0;
		$id = 0;
		while (true) {
			$p = $this->pages->get("{$selector}, id>$id");
			if(!$id = $p->id) break;
			$callback($p);
			$this->pages->uncacheAll($p);
			$num++;
		}
		return $num;
	}


	/**
	 * Insert a field into a template optionally at a specific position.
	 *
	 * @param Template|string   $template
	 * @param Field|string      $field
	 * @param Field|string|null $reference
	 * @param bool              $after
	 * @throws WireException
	 */
	protected function insertIntoTemplate ($template, $field, $reference = null, $after = true)
	{
		$template = $this->getTemplate($template);
		$fieldgroup = $template->fieldgroup;
		$method = $after ? 'insertAfter' : 'insertBefore';
		$field = $this->getField($field);

		// Get reference if supplied
		if($reference instanceof Field)
			$reference = $fieldgroup->get($reference->name);
		else if(is_string($reference))
			$reference = $fieldgroup->get($reference);

		// Insert field or append
		if($reference instanceof Field)
			$fieldgroup->$method($field, $reference);
		else
			$fieldgroup->append($field);

		$fieldgroup->save();
	}


	/**
	 * Removes a field from a template.
	 * Also removes all data for that field from pages using the template.
	 *
	 * @param Template|string   $template
	 * @param Field|string      $field
	 * @return bool     $success (true => success, false => failure)
	 * @throws WireException
	 */
	protected function removeFromTemplate($template, $field) {
		$t = $this->getTemplate($template);
		$f = $this->getField($field);
		$success = $t->fieldgroup->remove($f);
		$t->fieldgroup->save();
		return $success;
	}


	/**
	 * Removes a field from a set of templates.
	 * Also removes all data for that field from pages using any of the named templates.
	 *
	 * @param array|string   $template_names
	 * @param Field|string   $field
	 * @return array Array of template names from which the field was successfully removed
	 * @throws WireException
	 * @example removerFromTemplates('home,blog', 'first_name');
	 * @example removerFromTemplates(['home','blog'], 'first_name');
	 */
	protected function removeFromTemplates($template_names, $field) {
		$removed_names = array();
		if (is_string($template_names)) {
			$template_names = explode(',', $template_names);
		}

		if (empty($template_names)) $removed_names;

		foreach ($template_names as $tname) {
			if ($this->removeFromTemplate($tname, $field)) $removed_names[$tname] = $tname;
		}

		return $removed_names;
	}


	/**
	 * (re) labels the named field.
	 *
	 * @param Field|string $field The field to be re-labelled
	 * @param string       $label The new label for the field
	 * @throws WireException
	 */
	protected function labelField($field, $label)
	{
		$f = $this->getField($field);
		$f->label = $label;
		$f->save();
	}


	/**
	 * Deletes a field
	 *
	 * @param Field|string $field The field to be deleted
	 * @throws WireException
	 */
	protected function deleteField($field)
	{
		$field = $this->getField($field);
		$fgs   = $field->getFieldgroups();

		foreach($fgs as $fg){
			$fg->remove($field);
			$fg->save();
		}

		$this->fields->delete($field);
	}


	/**
	 * Conditionally deletes a field IFF it is not in use in any templates
	 *
	 * @param Field|string $field The field to be deleted
	 * @throws WireException
	 */
	protected function deleteFieldIfUnused($field)
	{
		$f = $this->getField($field);
		$t = $f->getTemplates();
		if (0 == $t->count()) {
			$this->fields->delete($f);
			$this->message("Deleted field '{$f->name}'");
		} else {
			$this->warning("Field '{$f->name}' is still present in templates [$t]");
		}
	}


	/**
	 * Edit a field in template context
	 *
	 * @param Template|string $template
	 * @param Field|string    $field
	 * @param callable        $callback
	 */
	protected function editInTemplateContext ($template, $field, callable $callback)
	{
		$template = $this->getTemplate($template);
		$fieldgroup = $template->fieldgroup;
		$field = $this->getField($field);

		$context = $fieldgroup->getField($field->name, true);
		$callback($context, $template);
		$this->fields->saveFieldgroupContext($context, $fieldgroup);
	}


	/**
	 * @param Template|string $template
	 * @return Template
	 * @throws WireException
	 */
	protected function getTemplate ($template)
	{
		$template = !is_string($template) ? $template : $this->templates->get($template);
		if(!$template instanceof Template) throw new WireException("Invalid template $template");
		return $template;
	}


	/**
	 * @param Field|string $field
	 * @return Field
	 * @throws WireException
	 */
	protected function getField ($field)
	{
		$field = !is_string($field) ? $field : $this->fields->get($field);
		if(!$field instanceof Field) throw new WireException("Invalid field $field");
		return $field;
	}
}
