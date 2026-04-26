<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ReminderFrequency;
use App\Entity\ReminderTemplate;
use App\Entity\ReminderType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ReminderTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ReminderTemplate::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Name');

        yield AssociationField::new('family', 'Family')
            ->setRequired(false)
            ->setHelp('Leave empty to send to ALL families.');

        yield ChoiceField::new('type', 'Type')
            ->setChoices(array_combine(
                array_map(fn(ReminderType $t) => $t->label(), ReminderType::cases()),
                array_map(fn(ReminderType $t) => $t->value, ReminderType::cases()),
            ));

        yield ChoiceField::new('frequency', 'Frequency')
            ->setChoices(array_combine(
                array_map(fn(ReminderFrequency $f) => $f->label(), ReminderFrequency::cases()),
                array_map(fn(ReminderFrequency $f) => $f->value, ReminderFrequency::cases()),
            ));

        yield IntegerField::new('sendHour', 'Send at hour (0–23)')
            ->setHelp('Command runs hourly — reminder fires in the matching hour.');

        yield IntegerField::new('dayOfWeek', 'Day of week (1=Mon … 7=Sun)')
            ->setRequired(false)
            ->setHelp('For weekly frequency only.');

        yield IntegerField::new('dayOfMonth', 'Day of month (1–31)')
            ->setRequired(false)
            ->setHelp('For monthly frequency only.');

        yield TextareaField::new('messageText', 'Message text');

        yield BooleanField::new('sendViaTelegram', 'Send via Telegram');
        yield BooleanField::new('sendViaEmail', 'Send via Email');
        yield BooleanField::new('isActive', 'Active');

        yield DateTimeField::new('lastSentAt', 'Last sent at')
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'Created at')
            ->hideOnForm();
    }
}
