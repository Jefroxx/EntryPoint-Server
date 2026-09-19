<?php

namespace Database\Factories;

use App\Models\BookSubject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BookFactory extends Factory
{
    // Generic, original title-building blocks — not real published titles.
    private const TITLE_TEMPLATES = [
        'Introduction to %s',
        'Principles of %s',
        'Fundamentals of %s',
        'Foundations of %s',
        'The Art of %s',
        'A Practical Guide to %s',
        'Understanding %s',
        'Modern %s',
        'Applied %s',
        'Essentials of %s',
        'Advanced %s',
        '%s in Practice',
        '%s: Theory and Application',
        'Exploring %s',
        'The Complete Handbook of %s',
    ];

    private const TOPICS = [
        'Data Structures', 'Algorithms', 'Web Development', 'Database Systems',
        'Calculus', 'Linear Algebra', 'Statistics', 'Organic Chemistry',
        'Cell Biology', 'Thermodynamics', 'Circuit Design', 'Project Management',
        'Macroeconomics', 'Microeconomics', 'Curriculum Design', 'Ethics',
        'Cognitive Psychology', 'Comparative Politics', 'Social Research',
        'Philippine Literature', 'Grammar and Composition', 'World Civilizations',
        'Philippine Revolution History', 'Watercolor Painting', 'Music Theory',
        'Community Health Nursing', 'Sustainable Agriculture', 'Constitutional Law',
        'Comparative Religion', 'Software Testing', 'Cloud Computing',
        'Machine Learning', 'Network Security', 'Human Anatomy', 'Marine Biology',
        'Environmental Science', 'Business Finance', 'Marketing Strategy',
        'Educational Psychology', 'Urban Planning', 'Renewable Energy',
    ];

    public function definition(): array
    {
        $title = sprintf(
            $this->faker->randomElement(self::TITLE_TEMPLATES),
            $this->faker->randomElement(self::TOPICS)
        );

        return [
            'uuid'            => Str::uuid(),
            'subjectID'       => BookSubject::inRandomOrder()->value('subjectID'),
            'areasOfLibrary'  => $this->faker->randomElement([
                'circulation', 'circulation', 'circulation', // weighted toward circulation
                'reserved', 'filipiniana', 'fiction', 'thesis', 'journal',
            ]),
            'title'           => $title,
            'classNumber'     => $this->faker->numerify('###.##') . ' ' . strtoupper($this->faker->lexify('?##')),
            'isbn'            => $this->faker->unique()->isbn13(),
            'publicationYear' => $this->faker->numberBetween(1998, 2026),
            'volume'          => $this->faker->boolean(15) ? (string) $this->faker->numberBetween(1, 3) : null,
            'edition'         => $this->faker->boolean(30) ? $this->faker->randomElement(['2nd', '3rd', '4th', 'International']) . ' Edition' : null,
            'pages'           => $this->faker->numberBetween(120, 650),
            'publisher'       => $this->faker->randomElement([
                'Rex Book Store', 'Anvil Publishing', 'C&E Publishing', 'Mutya Publishing House',
                'Pearson Education', 'Wiley Academic', 'Cengage Learning', 'Vibal Group',
            ]),
            'sourceOfFund'    => $this->faker->randomElement(['Institutional Fund', 'Donation', 'Student Fund', 'Government Subsidy']),
            'cost'            => $this->faker->randomFloat(2, 350, 3500),
            'copyNumber'      => null,
            'remarks'         => $this->faker->boolean(10) ? $this->faker->sentence(6) : null,
            'coverImageURL'   => null,
            'shelfLocation'   => strtoupper($this->faker->lexify('Shelf-??')) . '-' . $this->faker->numberBetween(1, 40),
        ];
    }
}
