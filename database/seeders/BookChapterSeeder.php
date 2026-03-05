<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BookChapters;

class BookChapterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $chapters = [
            [
                'chapter'    => 'CHAPTER 1',
                'chapter_title'       => 'The Ancient and Enduring History of CO₂ Therapy',
            ],
            [
                'chapter'    => 'CHAPTER 2',
                'chapter_title'       => 'The Oxygen Paradox: Why CO₂ Must Always Lead the Way',
            ],
            [
                'chapter'    => 'CHAPTER 3',
                'chapter_title'       => 'The Bohr Effect: How Carbon Dioxide Guides Oxygen to the Cells',
            ],
            [
                'chapter'    => 'CHAPTER 4',
                'chapter_title'       => 'The Breath of New Life CO₂ and the Miracle of Angiogenesis',
            ],
            [
                'chapter'    => 'CHAPTER 5',
                'chapter_title'       => 'The Spark Within: CO₂ and the Awakening of Mitochondria',
            ],
            [
                'chapter'    => 'CHAPTER 6',
                'chapter_title'       => 'The Silent Healer: Carbon Dioxide and the Resolution of Inflammation',
            ],
            [
                'chapter'    => 'CHAPTER 7',
                'chapter_title'       => 'The Stress Shield: CO₂ as an Antioxidant',
            ],
            [
                'chapter'    => 'CHAPTER 8',
                'chapter_title'       => "The Forgotten Regulator: CO₂'s Effects Independent of pH",
            ],
            [
                'chapter'    => 'CHAPTER 9',
                'chapter_title'       => 'The River of Life: Blood Flow, Stagnation, and the Restorative Power of CO₂',
            ],
            [
                'chapter'    => 'CHAPTER 10',
                'chapter_title'       => 'The Circulatory Engine: CO₂, the Diaphragmatic Pump, and the Revival of Flow',
            ],
            [
                'chapter'    => 'CHAPTER 11',
                'chapter_title'       => 'Nitric Oxide and Carbon Dioxide: Competing Messengers of Flow',
            ],
            [
                'chapter'    => 'CHAPTER 12',
                'chapter_title'       => 'The Vascular Battery: How CO₂ Charges the Fluid Body',
            ],
            [
                'chapter'    => 'CHAPTER 13',
                'chapter_title'       => 'The Emotional Clot: How Stress Stiffens the Blood and Strangles Flow',
            ],
            [
                'chapter'    => 'CHAPTER 14',
                'chapter_title'       => 'The Delivery Problem: Why Nutrients Don’t Work Like They Used To',
            ],
            [
                'chapter'    => 'CHAPTER 15',
                'chapter_title'       => 'The Hidden Inflammation: CO₂, Mast Cells, and the Silent March of Atherosclerosis',
            ],
            [
                'chapter'    => 'CHAPTER 16',
                'chapter_title'       => 'The Origins of Blood Pressure - CO₂ and the Microvascular Equation',
            ],
            [
                'chapter'    => 'CHAPTER 17',
                'chapter_title'       => 'Carbon Dioxide Therapy for Stroke Prevention and Recovery',
            ],
            [
                'chapter'    => 'CHAPTER 18',
                'chapter_title'       => 'CO₂ and Lipid Peroxidation',
            ],
            [
                'chapter'    => 'CHAPTER 19',
                'chapter_title'       => 'The Burden Within Fat, Inflammation, and the CO₂ Path to Metabolic Recovery',
            ],
            [
                'chapter'    => 'CHAPTER 20',
                'chapter_title'       => 'Carbon Dioxide and the Sugar Trap CO₂ as a Key to Unlocking Diabetes',
            ],
            [
                'chapter'    => 'CHAPTER 21',
                'chapter_title'       => 'Carbon Dioxide and the Architecture of Bone: A Forgotten Ally in the Fight Against Osteoporosis',
            ],
            [
                'chapter'    => 'CHAPTER 22',
                'chapter_title'       => 'The Breath that Opens the Lungs CO₂ Therapy for Pneumonia',
            ],
            [
                'chapter'    => 'CHAPTER 23',
                'chapter_title'       => 'CO₂, Brain Energy, and the Architecture of Mental Clarity',
            ],
            [
                'chapter'    => 'CHAPTER 24',
                'chapter_title'       => 'Between Terror and Tranquility: CO₂, Emotion, and the Chemistry of Fear',
            ],
            [
                'chapter'    => 'CHAPTER 25',
                'chapter_title'       => 'The Vagal Portal: CO₂, Safety, and the Restoration of Calm',
            ],
            [
                'chapter'    => 'CHAPTER 26',
                'chapter_title'       => 'The Breath of Resilience: Building CO₂ Tolerance for Vitality and Calm',
            ],
            [
                'chapter'    => 'CHAPTER 27',
                'chapter_title'       => "The Stress Mechanism Rediscovered: A Unified Theory of Degeneration and CO₂'s Restorative Role",
            ],
            [
                'chapter'    => 'CHAPTER 28',
                'chapter_title'       => 'CO₂, Structured Water, and the Living Matrix: From Ling to Pollack',
            ],
            [
                'chapter'    => 'CHAPTER 29',
                'chapter_title'       => 'The Microvascular Origin of Atherosclerosis: A New Frontier for CO₂ Therapy',
            ],
            [
                'chapter'    => 'CHAPTER 30',
                'chapter_title'       => 'The Regenerative Terrain: CO₂, Stem Cells, and the Elemental Triad',
            ],
            [
                'chapter'    => 'CHAPTER 31',
                'chapter_title'       => 'The Shadow Side of the Gas: Reconciling the Risks and Rewards of CO₂',
            ],
            [
                'chapter'    => 'CHAPTER 32',
                'chapter_title'       => 'The Longevity Equation: Rethinking Aging Through CO₂, Resilience, and the Lessons of Nature',
            ],
            [
                'chapter'    => 'CHAPTER 33',
                'chapter_title'       => 'CO₂ Therapy Modalities: Many Paths, One Molecule',
            ],
            
        ];

        foreach ($chapters as $chapterData) {
            BookChapters::updateOrCreate(
                ['chapter' => $chapterData['chapter']],
                $chapterData
            );
        }
    }
}
