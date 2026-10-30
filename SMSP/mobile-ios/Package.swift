// swift-tools-version: 5.9
import PackageDescription

let package = Package(
    name: "SchoolApp",
    platforms: [
        .iOS(.v14)
    ],
    products: [
        .executable(
            name: "SchoolApp",
            targets: ["SchoolApp"]),
    ],
    targets: [
        .executableTarget(
            name: "SchoolApp",
            path: "SchoolApp"),
    ]
)
